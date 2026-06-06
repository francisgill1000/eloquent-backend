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
        $this->assertNotNull($shop->category_confirmed_at); // locked at registration
    }

    public function test_rejects_unknown_category(): void
    {
        $this->postJson('/api/shops', [
            'name' => 'Bad Cat Shop',
            'phone' => '0550000001',
            'category_id' => 99,
            'is_verified' => true,
        ])->assertStatus(422);
    }

    public function test_old_shop_confirms_category_once_then_locked(): void
    {
        $shop = Shop::factory()->create(['category_id' => 1, 'category_confirmed_at' => null]);
        $token = $shop->createToken('test')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        // first confirmation works
        $this->postJson('/api/shop/category', ['category_id' => 9], $headers)
            ->assertOk()
            ->assertJsonPath('shop.category_id', 9);
        $this->assertNotNull($shop->fresh()->category_confirmed_at);

        // second attempt is rejected — locked
        $this->postJson('/api/shop/category', ['category_id' => 2], $headers)
            ->assertStatus(422);
        $this->assertSame(9, (int) $shop->fresh()->category_id);
    }

    public function test_phone_can_be_updated_via_shop_update(): void
    {
        $shop = Shop::factory()->create();

        $this->putJson("/api/shops/{$shop->id}", ['phone' => '0501112222'])
            ->assertOk();

        $this->assertSame('0501112222', $shop->fresh()->phone);
    }
}
