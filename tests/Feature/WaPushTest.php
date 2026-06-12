<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WaPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaPushTest extends TestCase
{
    use RefreshDatabase;

    /** Authenticate as a shop the way bizrezzy does: a real Sanctum bearer token. */
    private function actingShop(): Shop
    {
        $shop = Shop::factory()->create();
        $token = $shop->createToken('auth_token')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");

        return $shop;
    }

    public function test_vapid_key_returns_503_when_unconfigured(): void
    {
        config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);
        $this->actingShop();

        $this->getJson('/api/wa/push/vapid-key')->assertStatus(503);
    }

    public function test_vapid_key_returns_public_key(): void
    {
        config(['services.webpush.public_key' => 'pubkey123', 'services.webpush.private_key' => 'privkey123']);
        $this->actingShop();

        $this->getJson('/api/wa/push/vapid-key')->assertOk()->assertJson(['key' => 'pubkey123']);
    }

    public function test_subscribe_stores_subscription_once_scoped_to_the_shop(): void
    {
        $shop = $this->actingShop();
        $sub = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'p256value', 'auth' => 'authvalue'],
        ];

        $this->postJson('/api/wa/push/subscribe', $sub)->assertOk();
        $this->postJson('/api/wa/push/subscribe', $sub)->assertOk(); // idempotent

        $this->assertSame(1, WaPushSubscription::count());
        $this->assertSame('p256value', WaPushSubscription::first()->p256dh);
        // Per-shop scoping: the subscription belongs to the shop that registered it.
        $this->assertSame($shop->id, (int) WaPushSubscription::first()->shop_id);
    }

    public function test_unsubscribe_removes_subscription(): void
    {
        $this->actingShop();
        WaPushSubscription::create(['endpoint' => 'https://e/1', 'p256dh' => 'k', 'auth' => 'a']);

        $this->postJson('/api/wa/push/unsubscribe', ['endpoint' => 'https://e/1'])->assertOk();

        $this->assertSame(0, WaPushSubscription::count());
    }

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/wa/push/vapid-key')->assertStatus(401);
        $this->postJson('/api/wa/push/subscribe', [])->assertStatus(401);
    }
}
