<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_shop_starts_a_30_day_trial(): void
    {
        $res = $this->postJson('/api/shops', [
            'name' => 'Glow Salon', 'phone' => '+971500000000', 'category_id' => 1, 'is_verified' => true,
        ]);
        $res->assertSuccessful();
        $shop = Shop::where('name', 'Glow Salon')->firstOrFail();
        $sub = $shop->subscription()->firstOrFail();
        $this->assertSame('trialing', $sub->status);
        $this->assertTrue($sub->hasAccess());
    }

    public function test_create_subscription_intent_posts_amount_in_fils(): void
    {
        Http::fake([
            '*/payment_intent' => Http::response(
                ['id' => 'pi_1', 'redirect_url' => 'https://pay.ziina/x', 'status' => 'pending'], 200),
        ]);
        config(['services.ziina.api_key' => 'test', 'services.ziina.base_url' => 'https://api.ziina/api']);
        $shop = Shop::create(['name' => 'T', 'shop_code' => '900101', 'status' => 'active']);
        $out = app(\App\Services\Ziina::class)->createSubscriptionIntent($shop, 'monthly', 14900, [
            'success_url' => 'https://a/s', 'cancel_url' => 'https://a/c', 'failure_url' => 'https://a/f',
        ]);
        $this->assertSame('pi_1', $out['id']);
        Http::assertSent(fn ($r) => $r['amount'] === 14900 && $r['currency_code'] === 'AED');
    }

    public function test_gate_blocks_expired_and_allows_active_and_exempts_master(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'subscription.active'])
            ->get('/api/_probe', fn () => response()->json(['ok' => true]));

        $expired = Shop::create(['name' => 'E', 'shop_code' => '900102', 'status' => 'active']);
        $expired->subscription()->create(['status' => 'expired', 'access_until' => now()->subDay()]);
        $this->actingAs($expired)->getJson('/api/_probe')->assertStatus(402)
            ->assertJson(['error' => 'subscription_required']);

        $active = Shop::create(['name' => 'A', 'shop_code' => '900103', 'status' => 'active']);
        $active->subscription()->create(['status' => 'active', 'access_until' => now()->addDay()]);
        $this->actingAs($active)->getJson('/api/_probe')->assertOk();

        $master = Shop::create(['name' => 'M', 'shop_code' => '900104', 'status' => 'active', 'is_master' => true]);
        $this->actingAs($master)->getJson('/api/_probe')->assertOk();
    }

    public function test_checkout_creates_pending_payment_and_returns_redirect(): void
    {
        Http::fake([
            '*/payment_intent' => Http::response(
                ['id' => 'pi_9', 'redirect_url' => 'https://pay.ziina/9', 'status' => 'pending'], 200),
        ]);
        config(['services.ziina.api_key' => 'test', 'services.ziina.base_url' => 'https://api.ziina/api']);
        $shop = Shop::create(['name' => 'C', 'shop_code' => '900105', 'status' => 'active']);
        app(SubscriptionService::class)->startTrial($shop);

        $res = $this->actingAs($shop)->postJson('/api/shop/subscription/checkout', ['plan' => 'monthly']);
        $res->assertOk()->assertJson(['redirect_url' => 'https://pay.ziina/9', 'intent_id' => 'pi_9']);
        $this->assertDatabaseHas('subscription_payments', [
            'shop_id' => $shop->id, 'plan' => 'monthly', 'amount_fils' => 14900,
            'ziina_intent_id' => 'pi_9', 'status' => 'pending', 'period_days' => 30,
        ]);
    }

    public function test_status_returns_days_left_and_prices(): void
    {
        $shop = Shop::create(['name' => 'S', 'shop_code' => '900106', 'status' => 'active']);
        app(SubscriptionService::class)->startTrial($shop);
        $this->actingAs($shop)->getJson('/api/shop/subscription')
            ->assertOk()->assertJsonPath('status', 'trialing')
            ->assertJsonPath('prices.monthly', 14900)->assertJsonPath('prices.annual', 100000);
    }

    public function test_assistant_endpoint_is_gated_by_subscription(): void
    {
        $expired = Shop::create(['name' => 'X', 'shop_code' => '900107', 'status' => 'active']);
        $expired->subscription()->create(['status' => 'expired', 'access_until' => now()->subDay()]);
        $this->actingAs($expired)->getJson('/api/shop/assistant/conversations')->assertStatus(402);

        $active = Shop::create(['name' => 'Y', 'shop_code' => '900108', 'status' => 'active']);
        $active->subscription()->create(['status' => 'active', 'access_until' => now()->addDay()]);
        $this->actingAs($active)->getJson('/api/shop/assistant/conversations')->assertStatus(200);
    }

    public function test_webhook_marks_payment_paid_and_extends_access(): void
    {
        $shop = Shop::create(['name' => 'W', 'shop_code' => '900109', 'status' => 'active']);
        $shop->subscription()->create(['status' => 'expired', 'access_until' => now()->subDay()]);
        $payment = \App\Models\SubscriptionPayment::create([
            'shop_id' => $shop->id, 'plan' => 'annual', 'amount_fils' => 100000,
            'ziina_operation_id' => Str::uuid(), 'ziina_intent_id' => 'pi_paid',
            'status' => 'pending', 'period_days' => 365,
        ]);

        // Sign the payload the same way Ziina would, exercising the real
        // signature-verification path (a missing secret now rejects, not skips).
        $secret = 'test-webhook-secret';
        config(['services.ziina.webhook_secret' => $secret]);
        $event = [
            'event' => 'payment_intent.status.updated',
            'data' => ['id' => 'pi_paid', 'status' => 'completed'],
        ];
        $signature = hash_hmac('sha256', json_encode($event), $secret);
        $this->postJson('/api/ziina/webhook', $event, ['X-Hmac-Signature' => $signature])->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $sub = $shop->subscription()->first();
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->hasAccess());
    }
}
