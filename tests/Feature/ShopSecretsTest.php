<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shop login credentials (pin) and device_id must never leak through public
 * endpoints; owner-facing responses keep showing the PIN (bizrezzy displays
 * it on the Register, Profile and master screens).
 */
class ShopSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_shop_endpoints_hide_pin_and_device_id(): void
    {
        $shop = Shop::factory()->create(['device_id' => 'owner-device-1']);

        $list = $this->getJson('/api/shops')->assertOk()->json();
        $row = collect($list['data'])->firstWhere('id', $shop->id);
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('pin', $row);
        $this->assertArrayNotHasKey('device_id', $row);

        $detail = $this->getJson("/api/shops/{$shop->id}")->assertOk()->json();
        $this->assertArrayNotHasKey('pin', $detail);
        $this->assertArrayNotHasKey('device_id', $detail);
    }

    public function test_owner_login_and_register_still_return_pin(): void
    {
        $shop = Shop::factory()->create();

        $login = $this->postJson('/api/shops/login', [
            'shop_code' => $shop->shop_code,
            'pin' => $shop->pin,
        ])->assertCreated()->json();
        $this->assertSame($shop->pin, $login['shop']['pin']);
        $this->assertArrayNotHasKey('device_id', $login['shop']);

        $register = $this->postJson('/api/shops', [
            'name' => 'Fresh Cuts',
            'category_id' => 1,
        ])->assertCreated()->json();
        $this->assertNotEmpty($register['shop']['pin']);
    }

    public function test_auto_login_returns_pin_for_the_linked_device(): void
    {
        $shop = Shop::factory()->create(['device_id' => 'dev-owner-7']);

        $res = $this->withHeader('X-Device-Id', 'dev-owner-7')
            ->postJson('/api/shops/auto-login')
            ->assertOk()->json();

        $this->assertTrue($res['authenticated']);
        $this->assertSame($shop->pin, $res['shop']['pin']);
    }
}
