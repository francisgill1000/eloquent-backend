<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shop login credentials (pin, password) and device_id must never leak
 * through public endpoints. Since the switch to email+password login, `pin`
 * is dormant and is no longer exposed anywhere, including login/auto-login.
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

    public function test_owner_login_no_longer_returns_pin(): void
    {
        $shop = Shop::factory()->create(['email' => 'owner@example.com', 'password' => 'correct-horse']);

        $login = $this->postJson('/api/shops/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-horse',
        ])->assertCreated()->json();

        $this->assertArrayNotHasKey('pin', $login['shop']);
        $this->assertArrayNotHasKey('device_id', $login['shop']);
    }

    public function test_registration_no_longer_returns_pin(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $register = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Fresh Cuts',
            'email' => 'freshcuts@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 1,
        ])->assertCreated()->json();

        $this->assertArrayNotHasKey('pin', $register['shop']);
    }

    public function test_auto_login_no_longer_returns_pin(): void
    {
        $shop = Shop::factory()->create(['device_id' => 'dev-owner-7']);

        $res = $this->withHeader('X-Device-Id', 'dev-owner-7')
            ->postJson('/api/shops/auto-login')
            ->assertOk()->json();

        $this->assertTrue($res['authenticated']);
        $this->assertArrayNotHasKey('pin', $res['shop']);
    }
}
