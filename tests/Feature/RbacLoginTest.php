<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_by_user_pin_returns_user_and_permissions(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['shop_code' => '900001', 'pin' => '0000']);

        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->givePermissionTo('bookings.view');

        $user = ShopUser::factory()->create(['shop_id' => $shop->id, 'name' => 'Cara', 'login_pin' => '5678']);
        $user->assignRole($role);

        $res = $this->postJson('/api/shops/login', ['shop_code' => '900001', 'pin' => '5678']);

        $res->assertStatus(201)
            ->assertJsonPath('user.name', 'Cara')
            ->assertJsonPath('permissions.0', 'bookings.view');

        $this->assertDatabaseHas('personal_access_tokens', ['shop_user_id' => $user->id]);
    }

    public function test_legacy_shop_pin_still_logs_in_as_owner_equivalent(): void
    {
        $shop = Shop::factory()->create(['shop_code' => '900002', 'pin' => '4444']);

        $res = $this->postJson('/api/shops/login', ['shop_code' => '900002', 'pin' => '4444']);

        $res->assertStatus(201)->assertJsonPath('permissions.0', '*');
    }

    public function test_wrong_pin_is_rejected(): void
    {
        $shop = Shop::factory()->create(['shop_code' => '900003', 'pin' => '1234']);

        $this->postJson('/api/shops/login', ['shop_code' => '900003', 'pin' => '0000'])
            ->assertStatus(401);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['shop_code' => '900004', 'pin' => '2222']);
        ShopUser::factory()->create(['shop_id' => $shop->id, 'login_pin' => '3333', 'is_active' => false]);

        $this->postJson('/api/shops/login', ['shop_code' => '900004', 'pin' => '3333'])
            ->assertStatus(401);
    }
}
