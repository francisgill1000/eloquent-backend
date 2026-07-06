<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_current_user_and_permissions(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->givePermissionTo('bookings.view');
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);

        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();

        $this->withHeaders(['Authorization' => 'Bearer ' . $new->plainTextToken])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $u->id)
            ->assertJsonPath('permissions.0', 'bookings.view');
    }

    public function test_untagged_token_is_owner_equivalent(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        $token = $shop->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('permissions.0', '*')
            ->assertJsonPath('user', null);
    }
}
