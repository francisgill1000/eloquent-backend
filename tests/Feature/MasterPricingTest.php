<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterPricingTest extends TestCase
{
    use RefreshDatabase;

    private function master(): Shop
    {
        return Shop::create(['name' => 'M', 'shop_code' => '700110', 'status' => 'active', 'is_master' => true]);
    }

    public function test_master_can_read_and_update_pricing(): void
    {
        $m = $this->master();
        $this->actingAs($m)->getJson('/api/master/pricing')
            ->assertOk()->assertJson(['monthly' => 14900, 'annual' => 100000]);
        $this->actingAs($m)->patchJson('/api/master/pricing', ['monthly_fils' => 19900, 'annual_fils' => 120000])
            ->assertOk();
        $this->assertDatabaseHas('pricing', ['plan' => 'monthly', 'price_fils' => 19900]);
    }

    public function test_master_can_grant_days_to_a_shop(): void
    {
        $m = $this->master();
        $shop = Shop::create(['name' => 'G', 'shop_code' => '900201', 'status' => 'active']);
        $shop->subscription()->create(['status' => 'expired', 'access_until' => now()->subDay()]);
        $this->actingAs($m)->patchJson("/api/master/shops/{$shop->id}/subscription", ['grant_days' => 30])
            ->assertOk();
        $this->assertTrue($shop->subscription()->first()->hasAccess());
    }

    public function test_non_master_cannot_touch_pricing(): void
    {
        $shop = Shop::create(['name' => 'N', 'shop_code' => '900202', 'status' => 'active']);
        $this->actingAs($shop)->getJson('/api/master/pricing')->assertStatus(403);
    }
}
