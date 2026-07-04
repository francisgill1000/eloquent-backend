<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShopUserApiTest extends TestCase
{
    use RefreshDatabase;

    private ?ShopUser $owner = null;

    private function ownerToken(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id, 'login_pin' => '0001']);
        $u->assignRole($owner);
        $this->owner = $u;

        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();

        return $new->plainTextToken;
    }

    public function test_owner_creates_user_with_role(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        $token = $this->ownerToken($shop);
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Staff', 'guard_name' => 'web', 'team_id' => $shop->id]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/shop/users', [
                'name' => 'Bob',
                'login_pin' => '7777',
                'role_id' => $role->id,
                'is_active' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.role.name', 'Staff');

        $this->assertDatabaseHas('shop_users', ['shop_id' => $shop->id, 'name' => 'Bob', 'login_pin' => '7777']);
    }

    public function test_pin_must_be_unique_within_shop(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        $token = $this->ownerToken($shop); // owner uses 0001

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/shop/users', ['name' => 'Dup', 'login_pin' => '0001', 'role_id' => null, 'is_active' => true])
            ->assertStatus(422);
    }

    public function test_users_are_tenant_isolated(): void
    {
        (new PermissionSeeder())->run();
        $shopA = Shop::factory()->create();
        $shopB = Shop::factory()->create();
        $foreign = ShopUser::factory()->create(['shop_id' => $shopB->id]);

        $token = $this->ownerToken($shopA);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/shop/users/{$foreign->id}", ['name' => 'x', 'role_id' => null, 'is_active' => true])
            ->assertStatus(404);
    }

    public function test_cannot_delete_last_owner(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        $token = $this->ownerToken($shop);
        $ownerId = $this->owner->id;

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/shop/users/{$ownerId}")
            ->assertStatus(422);
    }
}
