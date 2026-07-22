<?php

namespace Tests\Feature;

use App\Models\CreditPack;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Credits\HuntCreditService;
use App\Services\Leads\Contracts\LeadSourceInterface;
use App\Services\Leads\LeadSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class HuntCreditsTest extends TestCase
{
    use RefreshDatabase;

    private function master(): Shop
    {
        return Shop::create(['name' => 'M', 'shop_code' => '820100', 'status' => 'active', 'is_master' => true]);
    }

    /** A Hunt shop (leads module) with a token tagged to a ShopUser. Deliberately
     *  NO subscription — Hunt must work independently of the Lens paywall. */
    private function huntShop(string $code): array
    {
        $shop = Shop::factory()->create(['shop_code' => $code, 'modules' => ['leads']]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        // WS2 gated /shop/leads/* behind can.perm:leads.*. Owner bypasses all
        // permission checks (App\Support\Rbac::isOwner), so make the acting user
        // an Owner for this shop's team.
        setPermissionsTeamId($shop->id);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]
        ));
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function fakeSource(array $results): void
    {
        $this->app->instance(LeadSourceInterface::class, $this->fakeSourceInstance($results));
    }

    private function fakeSourceInstance(array $results): LeadSourceInterface
    {
        return new class($results) implements LeadSourceInterface {
            public function __construct(private array $results) {}

            public function search(string $query, ?string $area): array
            {
                return $this->results;
            }

            public function key(): string
            {
                return 'google_places';
            }
        };
    }

    private function sample(): array
    {
        return [[
            'name' => 'Marina Barbers', 'phone' => '050 123 4567', 'website' => null,
            'address' => 'Dubai Marina', 'category' => 'hair_care', 'lat' => 25.08, 'lng' => 55.14,
            'rating' => 4.6, 'external_ref' => 'place_A', 'source' => 'google_places',
        ]];
    }

    // ---- Manual grant (the priority path: sell via Ziina link, grant by hand) ----

    public function test_master_can_grant_credits_to_a_shop(): void
    {
        $m = $this->master();
        [$shop] = $this->huntShop('820101');

        $this->actingAs($m)
            ->postJson("/api/master/shops/{$shop->id}/credits", ['amount' => 200, 'note' => 'ziina order 5'])
            ->assertOk()
            ->assertJson(['ok' => true, 'credits' => 200]);

        $this->assertSame(200, app(HuntCreditService::class)->balance($shop->fresh()));
        $this->assertDatabaseHas('hunt_credit_transactions', [
            'shop_id' => $shop->id, 'amount' => 200, 'reason' => 'grant',
        ]);
    }

    public function test_non_master_cannot_grant_credits(): void
    {
        [$shop, $token] = $this->huntShop('820102');

        $this->auth($token)
            ->postJson("/api/master/shops/{$shop->id}/credits", ['amount' => 200])
            ->assertStatus(403);

        $this->assertSame(0, app(HuntCreditService::class)->balance($shop->fresh()));
    }

    public function test_grant_is_scoped_to_the_target_shop(): void
    {
        $m = $this->master();
        [$a] = $this->huntShop('820103');
        [$b] = $this->huntShop('820104');

        $this->actingAs($m)->postJson("/api/master/shops/{$a->id}/credits", ['amount' => 500])->assertOk();

        $this->assertSame(500, app(HuntCreditService::class)->balance($a->fresh()));
        $this->assertSame(0, app(HuntCreditService::class)->balance($b->fresh()));
    }

    // ---- Shop-facing balance + packs (for the low-balance top-up prompt) ----

    public function test_shop_reads_its_balance_and_the_active_packs(): void
    {
        [$shop, $token] = $this->huntShop('820105');
        app(HuntCreditService::class)->grant($shop, 42);

        $res = $this->auth($token)->getJson('/api/shop/leads/credits')->assertOk();
        $res->assertJsonPath('credits', 42);
        // Three packs are seeded by migration.
        $res->assertJsonCount(3, 'packs');
    }

    // ---- Search gating on the credit balance ----

    public function test_live_search_debits_one_credit(): void
    {
        $this->fakeSource($this->sample());
        [$shop, $token] = $this->huntShop('820106');
        app(HuntCreditService::class)->grant($shop, 3);

        $res = $this->auth($token)->getJson('/api/shop/leads/search?category=salon&area=Marina')->assertOk();
        $res->assertJsonPath('meta.from_cache', false);
        $res->assertJsonPath('meta.credits', 2);

        $this->assertSame(2, app(HuntCreditService::class)->balance($shop->fresh()));
        $this->assertDatabaseHas('hunt_credit_transactions', [
            'shop_id' => $shop->id, 'amount' => -1, 'reason' => 'search',
        ]);
    }

    public function test_repeat_search_is_free_from_cache(): void
    {
        $this->fakeSource($this->sample());
        [$shop, $token] = $this->huntShop('820107');
        app(HuntCreditService::class)->grant($shop, 3);

        $this->auth($token)->getJson('/api/shop/leads/search?category=salon&area=Marina')->assertOk();
        $repeat = $this->auth($token)->getJson('/api/shop/leads/search?category=salon&area=Marina')->assertOk();
        $repeat->assertJsonPath('meta.from_cache', true);
        $repeat->assertJsonPath('meta.credits', 2); // unchanged — cache hit is free

        $this->assertSame(2, app(HuntCreditService::class)->balance($shop->fresh()));
    }

    public function test_search_is_blocked_with_no_credits(): void
    {
        $this->fakeSource($this->sample());
        [$shop, $token] = $this->huntShop('820108'); // 0 credits

        $this->auth($token)->getJson('/api/shop/leads/search?category=salon')
            ->assertStatus(429)
            ->assertJsonPath('error', 'insufficient_credits')
            ->assertJsonPath('credits', 0);

        // Nothing was spent and no live-search side effects were recorded.
        $this->assertDatabaseCount('hunt_credit_transactions', 0);
        $this->assertDatabaseCount('lead_search_logs', 0);
    }

    /** The credit is reserved before the provider call, and refunded on
     *  provider failure — but a failure while caching/logging the results
     *  AFTER a successful provider call must refund too, or the shop pays
     *  for a search it never actually received. */
    public function test_search_refunds_credit_when_finalizing_results_fails(): void
    {
        [$shop, $token] = $this->huntShop('820116');
        app(HuntCreditService::class)->grant($shop, 3);

        $search = Mockery::mock(LeadSearchService::class, [
            $this->fakeSourceInstance($this->sample()),
            app(HuntCreditService::class),
        ])->makePartial();
        $search->shouldAllowMockingProtectedMethods();
        $search->shouldReceive('finalizeSearch')->once()->andThrow(new \RuntimeException('cache write failed'));
        $this->app->instance(LeadSearchService::class, $search);

        $this->auth($token)->getJson('/api/shop/leads/search?category=salon&area=Marina')->assertStatus(500);

        $this->assertSame(3, app(HuntCreditService::class)->balance($shop->fresh()));
        $this->assertDatabaseCount('lead_search_logs', 0);
    }

    public function test_hunt_works_without_a_lens_subscription(): void
    {
        // huntShop() has no subscription at all; with credits it must still search.
        $this->fakeSource($this->sample());
        [$shop, $token] = $this->huntShop('820109');
        app(HuntCreditService::class)->grant($shop, 1);

        $this->auth($token)->getJson('/api/shop/leads/search?category=salon')->assertOk();
    }

    // ---- Master-editable packs ----

    public function test_master_can_crud_credit_packs(): void
    {
        $m = $this->master();

        // Seeded list.
        $this->actingAs($m)->getJson('/api/master/credit-packs')->assertOk()->assertJsonCount(3, 'data');

        // Create.
        $created = $this->actingAs($m)->postJson('/api/master/credit-packs', [
            'name' => 'Mega', 'credits' => 5000, 'price_fils' => 299900, 'sort' => 4,
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('credit_packs', ['name' => 'Mega', 'credits' => 5000, 'price_fils' => 299900]);

        // Update price (master-editable, no deploy).
        $this->actingAs($m)->patchJson("/api/master/credit-packs/{$created['id']}", ['price_fils' => 249900])
            ->assertOk();
        $this->assertDatabaseHas('credit_packs', ['id' => $created['id'], 'price_fils' => 249900]);

        // Delete.
        $this->actingAs($m)->deleteJson("/api/master/credit-packs/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('credit_packs', ['id' => $created['id']]);
    }

    public function test_non_master_cannot_touch_credit_packs(): void
    {
        [, $token] = $this->huntShop('820110');
        $this->auth($token)->getJson('/api/master/credit-packs')->assertStatus(403);
    }

    // ---- Self-serve purchase via Ziina checkout (sandbox) ----

    private function starterPackId(): int
    {
        return CreditPack::where('name', 'Starter')->value('id');
    }

    /** Fake Ziina's hosted-page API so no real HTTP/payment happens. */
    private function fakeZiina(string $intentId = 'pi_hunt'): void
    {
        Http::fake([
            '*/payment_intent' => Http::response([
                'id' => $intentId,
                'redirect_url' => "https://pay.ziina/{$intentId}",
                'embedded_url' => "https://pay.ziina.com/embedded/{$intentId}",
                'status' => 'pending',
            ], 200),
        ]);
        config([
            'services.ziina.api_key' => 'test',
            'services.ziina.base_url' => 'https://api.ziina/api',
            'services.ziina.admin_return_base' => 'https://admin.test',
            'services.ziina.webhook_secret' => null,
            // Global flag LIVE — proves credit packs still go sandbox independently.
            'services.ziina.test' => false,
            'services.ziina.hunt_test' => true,
        ]);
    }

    public function test_flagged_shop_checkout_creates_pending_purchase_and_returns_redirect(): void
    {
        $this->fakeZiina('pi_hunt1');
        [$shop, $token] = $this->huntShop('820111');
        $shop->update(['hunt_self_serve' => true]);
        $pack = $this->starterPackId();

        $this->auth($token)
            ->postJson('/api/shop/leads/purchase', ['pack_id' => $pack])
            ->assertOk()
            ->assertJson([
                'redirect_url' => 'https://pay.ziina/pi_hunt1',
                'embedded_url' => 'https://pay.ziina.com/embedded/pi_hunt1',
                'intent_id' => 'pi_hunt1',
            ]);

        // A pending order is recorded; credits are NOT granted until the webhook.
        $this->assertDatabaseHas('credit_purchases', [
            'shop_id' => $shop->id, 'pack_id' => $pack, 'credits' => 200, 'amount_fils' => 19900,
            'ziina_intent_id' => 'pi_hunt1', 'status' => 'pending',
        ]);
        $this->assertSame(0, app(HuntCreditService::class)->balance($shop->fresh()));
        // Amount is sent to Ziina in fils, and credit packs go to Ziina in TEST
        // mode (no real money) regardless of the global live subscription toggle.
        Http::assertSent(fn ($r) => $r->url() === 'https://api.ziina/api/payment_intent'
            && $r['amount'] === 19900 && $r['currency_code'] === 'AED' && $r['test'] === true);
    }

    /** Signs a webhook payload the same way Ziina would, so tests exercise
     *  the real signature-verification path instead of bypassing it. */
    private function postSignedZiinaWebhook(array $payload)
    {
        $secret = 'test-webhook-secret';
        config(['services.ziina.webhook_secret' => $secret]);
        $signature = hash_hmac('sha256', json_encode($payload), $secret);

        return $this->postJson('/api/ziina/webhook', $payload, ['X-Hmac-Signature' => $signature]);
    }

    /** The stored purchase row's operation_id must be the SAME one sent to
     *  Ziina — otherwise it's useless for reconciliation, and a retried
     *  purchase attempt can't be deduped against what Ziina actually saw
     *  (mirrors the stable key already used for booking invoices). */
    public function test_checkout_operation_id_matches_the_stored_purchase_row(): void
    {
        $this->fakeZiina('pi_opid');
        [$shop, $token] = $this->huntShop('820117');
        $shop->update(['hunt_self_serve' => true]);
        $pack = $this->starterPackId();

        $this->auth($token)->postJson('/api/shop/leads/purchase', ['pack_id' => $pack])->assertOk();

        $purchase = \App\Models\CreditPurchase::where('shop_id', $shop->id)->firstOrFail();

        Http::assertSent(fn ($r) => $r->url() === 'https://api.ziina/api/payment_intent'
            && $r['operation_id'] === $purchase->ziina_operation_id);
    }

    public function test_webhook_completes_purchase_and_grants_credits_once(): void
    {
        [$shop] = $this->huntShop('820112b');
        $purchase = \App\Models\CreditPurchase::create([
            'shop_id' => $shop->id, 'pack_id' => $this->starterPackId(),
            'credits' => 200, 'amount_fils' => 19900,
            'ziina_operation_id' => Str::uuid(), 'ziina_intent_id' => 'pi_done', 'status' => 'pending',
        ]);

        $event = [
            'event' => 'payment_intent.status.updated',
            'data' => ['id' => 'pi_done', 'status' => 'completed'],
        ];
        $this->postSignedZiinaWebhook($event)->assertOk();

        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertSame(200, app(HuntCreditService::class)->balance($shop->fresh()));
        $tx = \App\Models\HuntCreditTransaction::where('shop_id', $shop->id)->where('reason', 'purchase')->first();
        $this->assertFalse($tx->meta['simulated']);
        $this->assertSame('ziina', $tx->meta['via']);

        // Ziina retries webhooks — a second delivery must NOT double-grant.
        $this->postSignedZiinaWebhook($event)->assertOk();
        $this->assertSame(200, app(HuntCreditService::class)->balance($shop->fresh()));
        $this->assertSame(1, \App\Models\HuntCreditTransaction::where('shop_id', $shop->id)->where('reason', 'purchase')->count());
    }

    /** Two webhook deliveries racing to mark the same purchase paid must not
     *  both "win" — only the first status transition may succeed, so only
     *  one can go on to grant credits. This is the guard the webhook relies
     *  on to stay safe under concurrent delivery, not just sequential retries. */
    public function test_credit_purchase_mark_paid_once_allows_only_one_winner(): void
    {
        [$shop] = $this->huntShop('820114');
        $purchase = \App\Models\CreditPurchase::create([
            'shop_id' => $shop->id, 'pack_id' => $this->starterPackId(),
            'credits' => 200, 'amount_fils' => 19900,
            'ziina_operation_id' => Str::uuid(), 'ziina_intent_id' => 'pi_race', 'status' => 'pending',
        ]);

        // Simulate two overlapping deliveries: each loads its own copy of the
        // row (as two separate HTTP requests would), then both race to claim it.
        $copyA = \App\Models\CreditPurchase::find($purchase->id);
        $copyB = \App\Models\CreditPurchase::find($purchase->id);

        $first = $copyA->markPaidOnce();
        $second = $copyB->markPaidOnce();

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame('paid', $purchase->fresh()->status);
    }

    /** A missing webhook secret must never fall back to "accept unsigned" —
     *  that would let anyone POST a fake completed-payment event. */
    public function test_webhook_rejects_when_secret_not_configured(): void
    {
        config(['services.ziina.webhook_secret' => null]);
        [$shop] = $this->huntShop('820115');
        \App\Models\CreditPurchase::create([
            'shop_id' => $shop->id, 'pack_id' => $this->starterPackId(),
            'credits' => 200, 'amount_fils' => 19900,
            'ziina_operation_id' => Str::uuid(), 'ziina_intent_id' => 'pi_unsigned', 'status' => 'pending',
        ]);

        $this->postJson('/api/ziina/webhook', [
            'event' => 'payment_intent.status.updated',
            'data' => ['id' => 'pi_unsigned', 'status' => 'completed'],
        ])->assertStatus(500);

        $this->assertSame(0, app(HuntCreditService::class)->balance($shop->fresh()));
        $this->assertSame('pending', \App\Models\CreditPurchase::where('ziina_intent_id', 'pi_unsigned')->first()->status);
    }

    public function test_webhook_ignores_non_completed_status(): void
    {
        [$shop] = $this->huntShop('820112c');
        \App\Models\CreditPurchase::create([
            'shop_id' => $shop->id, 'pack_id' => $this->starterPackId(),
            'credits' => 200, 'amount_fils' => 19900,
            'ziina_operation_id' => Str::uuid(), 'ziina_intent_id' => 'pi_pending', 'status' => 'pending',
        ]);

        $this->postSignedZiinaWebhook([
            'event' => 'payment_intent.status.updated',
            'data' => ['id' => 'pi_pending', 'status' => 'requires_payment_instrument'],
        ])->assertOk();

        $this->assertSame(0, app(HuntCreditService::class)->balance($shop->fresh()));
    }

    public function test_unflagged_shop_cannot_start_checkout(): void
    {
        [$shop, $token] = $this->huntShop('820112'); // flag off by default

        $this->auth($token)
            ->postJson('/api/shop/leads/purchase', ['pack_id' => $this->starterPackId()])
            ->assertStatus(403)
            ->assertJsonPath('error', 'self_serve_disabled');

        $this->assertDatabaseCount('credit_purchases', 0);
    }

    public function test_master_can_start_checkout(): void
    {
        $this->fakeZiina('pi_master');
        $m = $this->master();

        $this->actingAs($m)
            ->postJson('/api/shop/leads/purchase', ['pack_id' => $this->starterPackId()])
            ->assertOk()
            ->assertJsonPath('intent_id', 'pi_master');
    }

    public function test_checkout_unknown_or_inactive_pack_is_404(): void
    {
        [$shop, $token] = $this->huntShop('820113');
        $shop->update(['hunt_self_serve' => true]);

        $this->auth($token)
            ->postJson('/api/shop/leads/purchase', ['pack_id' => 999999])
            ->assertStatus(404);
    }

    public function test_credits_endpoint_reports_can_purchase(): void
    {
        [$shop, $token] = $this->huntShop('820114');

        $this->auth($token)->getJson('/api/shop/leads/credits')
            ->assertOk()->assertJsonPath('can_purchase', false);

        $shop->update(['hunt_self_serve' => true]);
        $this->auth($token)->getJson('/api/shop/leads/credits')
            ->assertOk()->assertJsonPath('can_purchase', true);
    }

    public function test_credits_endpoint_reports_embedded_checkout_flag(): void
    {
        [, $token] = $this->huntShop('820118');

        config(['services.ziina.hunt_embedded' => false]);
        $this->auth($token)->getJson('/api/shop/leads/credits')
            ->assertOk()->assertJsonPath('embedded_checkout', false);

        config(['services.ziina.hunt_embedded' => true]);
        $this->auth($token)->getJson('/api/shop/leads/credits')
            ->assertOk()->assertJsonPath('embedded_checkout', true);
    }

    public function test_purchase_is_tenant_scoped(): void
    {
        [$a] = $this->huntShop('820115');
        $a->update(['hunt_self_serve' => true]);
        [, $tokenB] = $this->huntShop('820116'); // B not flagged

        // B cannot purchase just because A is flagged.
        $this->auth($tokenB)
            ->postJson('/api/shop/leads/purchase', ['pack_id' => $this->starterPackId()])
            ->assertStatus(403);
    }

    public function test_master_can_toggle_self_serve_flag(): void
    {
        $m = $this->master();
        [$shop] = $this->huntShop('820117');

        $this->actingAs($m)
            ->patchJson("/api/master/shops/{$shop->id}", ['hunt_self_serve' => true])
            ->assertOk()
            ->assertJsonPath('data.hunt_self_serve', true);

        $this->assertTrue((bool) $shop->fresh()->hunt_self_serve);
    }

    // ---- Abandoned-checkout (pending) cleanup ----

    private function mkPurchase(int $shopId, string $status, int $daysAgo): \App\Models\CreditPurchase
    {
        $p = \App\Models\CreditPurchase::create([
            'shop_id' => $shopId, 'pack_id' => $this->starterPackId(),
            'credits' => 200, 'amount_fils' => 19900,
            'ziina_operation_id' => Str::uuid(), 'status' => $status,
        ]);
        \App\Models\CreditPurchase::where('id', $p->id)->update(['created_at' => now()->subDays($daysAgo)]);

        return $p->fresh();
    }

    public function test_command_expires_old_pending_only(): void
    {
        [$shop] = $this->huntShop('820119');
        $old = $this->mkPurchase($shop->id, 'pending', 2);
        $recent = $this->mkPurchase($shop->id, 'pending', 0);
        $paid = $this->mkPurchase($shop->id, 'paid', 5);

        $this->artisan('hunt:expire-pending-purchases')->assertSuccessful();

        $this->assertSame('failed', $old->fresh()->status);   // abandoned -> failed
        $this->assertSame('pending', $recent->fresh()->status); // still within window
        $this->assertSame('paid', $paid->fresh()->status);      // never touch paid
    }

    public function test_credits_endpoint_lazily_expires_this_shops_stale_pending(): void
    {
        [$a, $tokenA] = $this->huntShop('820120');
        [$b] = $this->huntShop('820121');
        $aOld = $this->mkPurchase($a->id, 'pending', 2);
        $aRecent = $this->mkPurchase($a->id, 'pending', 0);
        $bOld = $this->mkPurchase($b->id, 'pending', 2);

        $this->auth($tokenA)->getJson('/api/shop/leads/credits')->assertOk();

        $this->assertSame('failed', $aOld->fresh()->status);
        $this->assertSame('pending', $aRecent->fresh()->status);
        $this->assertSame('pending', $bOld->fresh()->status); // other shop untouched (scoped)
    }
}
