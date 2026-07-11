<?php

namespace Tests\Feature;

use App\Models\CreditPack;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Credits\HuntCreditService;
use App\Services\Leads\Contracts\LeadSourceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->app->instance(LeadSourceInterface::class, new class($results) implements LeadSourceInterface {
            public function __construct(private array $results) {}

            public function search(string $query, ?string $area): array
            {
                return $this->results;
            }

            public function key(): string
            {
                return 'google_places';
            }
        });
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
}
