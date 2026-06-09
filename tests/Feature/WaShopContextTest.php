<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaShopContextTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(Shop $shop, string $phoneNumberId = 'pn_ctx_1', ?string $token = 'shop-token-xyz'): WaAccount
    {
        return WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000009',
            'phone_number_id' => $phoneNumberId,
            'waba_id' => 'waba_ctx',
            'token' => $token,
        ]);
    }

    public function test_returns_shop_context_with_token_for_known_number(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9]);
        $this->makeAccount($shop);

        $response = $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/shop-context?phone_number_id=pn_ctx_1');

        $response->assertOk()->assertJson([
            'found' => true,
            'shop_name' => 'Glow Salon',
            'category' => 'Salon',
            'phone_number_id' => 'pn_ctx_1',
            'token' => 'shop-token-xyz',
        ]);
    }

    public function test_falls_back_to_default_token_when_account_token_empty(): void
    {
        config([
            'services.whatsapp.relay_secret' => 'relay-secret',
            'services.whatsapp.default_token' => 'system-default-token',
        ]);
        $shop = Shop::factory()->create();
        $this->makeAccount($shop, 'pn_ctx_2', null);

        $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/shop-context?phone_number_id=pn_ctx_2')
            ->assertOk()
            ->assertJson(['found' => true, 'token' => 'system-default-token']);
    }

    public function test_returns_not_found_for_unknown_number(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);

        $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/shop-context?phone_number_id=nope')
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    public function test_returns_shop_persona_when_set(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);
        $shop = Shop::factory()->create(['persona' => 'You are Glow Salon. Keep it short.']);
        $this->makeAccount($shop, 'pn_ctx_persona');

        $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/shop-context?phone_number_id=pn_ctx_persona')
            ->assertOk()
            ->assertJson(['found' => true, 'persona' => 'You are Glow Salon. Keep it short.']);
    }

    public function test_persona_is_null_when_unset(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);
        $shop = Shop::factory()->create();
        $this->makeAccount($shop, 'pn_ctx_nopersona');

        $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/shop-context?phone_number_id=pn_ctx_nopersona')
            ->assertOk()
            ->assertJson(['found' => true, 'persona' => null]);
    }

    public function test_rejects_missing_or_wrong_secret(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);

        $this->getJson('/api/wa/shop-context?phone_number_id=pn_ctx_1')->assertForbidden();
        $this->withHeader('X-Relay-Secret', 'wrong')
            ->getJson('/api/wa/shop-context?phone_number_id=pn_ctx_1')->assertForbidden();
    }
}
