<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopSimulationControllerTest extends TestCase
{
    use RefreshDatabase;

    // Mirrors OwnerAssistantControllerTest::authShop() — the repo's proven pattern.
    private function actingShop(): Shop
    {
        $shop = Shop::create(['name' => 'FreshCuts', 'shop_code' => '1001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);
        return $shop;
    }

    public function test_get_returns_default_when_none_saved(): void
    {
        $shop = $this->actingShop();
        // Shop offerings live in `catalogs` (Catalog: title + price). Creating a shop
        // does NOT auto-seed catalogs, so this is the shop's first (and only) catalog.
        $shop->catalogs()->create(['title' => 'Hair Cut', 'price' => '150.00']);

        $res = $this->getJson('/api/shop/simulation')->assertOk();

        $res->assertJsonPath('script.voices.owner', 'shimmer');
        $res->assertJsonPath('script.voices.assistant', 'nova');
        $this->assertNotEmpty($res->json('script.turns'));
        // Default must be built from the shop's real catalog — no hardcoded identity.
        $this->assertStringContainsString('Hair Cut', json_encode($res->json('script')));
        $this->assertSame('Hair Cut', $res->json('script.booking.service'));
        // Fake number only.
        $this->assertNotEmpty($res->json('script.booking.customer_phone'));
    }

    public function test_put_saves_and_get_returns_it(): void
    {
        $this->actingShop();
        $script = [
            'turns' => [
                ['who' => 'owner', 'text' => 'Book Mia for a facial tomorrow at 2.'],
                ['who' => 'assistant', 'text' => 'Done — Mia is booked.'],
            ],
            'booking' => [
                'customer_name' => 'Mia', 'customer_phone' => '055 010 2030',
                'service' => 'Facial', 'price' => '200.00',
                'date' => '2026-07-09', 'start_time' => '14:00', 'end_time' => '14:45',
                'staff_name' => 'Aisha',
            ],
            'voices' => ['owner' => 'coral', 'assistant' => 'nova'],
            'thinking_ms' => 1200,
        ];

        $this->putJson('/api/shop/simulation', ['script' => $script])->assertOk();

        $this->getJson('/api/shop/simulation')
            ->assertOk()
            ->assertJsonPath('script.turns.0.text', 'Book Mia for a facial tomorrow at 2.')
            ->assertJsonPath('script.voices.owner', 'coral');
    }

    public function test_put_null_clears_back_to_default(): void
    {
        $shop = $this->actingShop();
        $shop->catalogs()->create(['title' => 'Hair Cut', 'price' => '150.00']);
        $this->putJson('/api/shop/simulation', ['script' => ['turns' => [['who' => 'owner', 'text' => 'x']], 'booking' => [], 'voices' => ['owner' => 'coral', 'assistant' => 'nova'], 'thinking_ms' => 1000]])->assertOk();

        $this->putJson('/api/shop/simulation', ['script' => null])->assertOk();

        $this->getJson('/api/shop/simulation')->assertOk()->assertJsonPath('script.voices.owner', 'shimmer');
    }

    public function test_requires_shop_auth(): void
    {
        // Routes sit in the `auth:sanctum` group → unauthenticated JSON request = 401.
        $this->getJson('/api/shop/simulation')->assertStatus(401);
    }
}
