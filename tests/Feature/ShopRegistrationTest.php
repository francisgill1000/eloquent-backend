<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_with_name_and_phone_and_returns_credentials(): void
    {
        $response = $this->postJson('/api/shops', [
            'name' => 'Shakaina Salon',
            'phone' => '0554501483',
            'category_id' => 1,
            'is_verified' => true,
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('token'));
        $this->assertNotEmpty($response->json('shop.shop_code'));
        $this->assertNotEmpty($response->json('shop.pin'));

        $shop = Shop::where('name', 'Shakaina Salon')->first();
        $this->assertNotNull($shop);
        $this->assertSame('0554501483', $shop->phone);
    }

    public function test_phone_can_be_updated_via_shop_update(): void
    {
        $shop = Shop::factory()->create();

        $this->putJson("/api/shops/{$shop->id}", ['phone' => '0501112222'])
            ->assertOk();

        $this->assertSame('0501112222', $shop->fresh()->phone);
    }
}
