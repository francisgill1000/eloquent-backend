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
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);
        $this->owner = $u;

        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();

        return $new->plainTextToken;
    }

    public function test_owner_creates_user_with_role(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        $token = $this->ownerToken($shop);
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Staff', 'guard_name' => 'web', 'team_id' => $shop->id]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/shop/users', [
                'name' => 'Bob',
                'email' => 'bob@example.com',
                'password' => 'at-least-8-chars',
                'role_id' => $role->id,
                'is_active' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.role.name', 'Staff')
            ->assertJsonPath('data.email', 'bob@example.com');

        $this->assertDatabaseHas('shop_users', ['shop_id' => $shop->id, 'name' => 'Bob', 'email' => 'bob@example.com']);
    }

    public function test_email_must_be_unique_across_the_platform(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        $token = $this->ownerToken($shop);
        ShopUser::factory()->create(['shop_id' => $shop->id, 'email' => 'taken@example.com']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/shop/users', [
                'name' => 'Dup',
                'email' => 'taken@example.com',
                'password' => 'at-least-8-chars',
                'role_id' => null,
                'is_active' => true,
            ])
            ->assertStatus(422);
    }

    public function test_password_is_optional_on_update_and_keeps_the_old_one_when_blank(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        $token = $this->ownerToken($shop);
        $staff = ShopUser::factory()->create(['shop_id' => $shop->id, 'email' => 'staff@example.com', 'password' => 'original-pass']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/shop/users/{$staff->id}", [
                'name' => 'Staff Renamed',
                'email' => 'staff@example.com',
                'role_id' => null,
                'is_active' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'staff@example.com');

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('original-pass', $staff->fresh()->password));
    }

    public function test_users_are_tenant_isolated(): void
    {
        (new PermissionSeeder())->run();
        $shopA = Shop::factory()->trialing()->create();
        $shopB = Shop::factory()->trialing()->create();
        $foreign = ShopUser::factory()->create(['shop_id' => $shopB->id]);

        $token = $this->ownerToken($shopA);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/shop/users/{$foreign->id}", [
                'name' => 'x',
                'email' => 'x@example.com',
                'role_id' => null,
                'is_active' => true,
            ])
            ->assertStatus(404);
    }

    public function test_cannot_delete_last_owner(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        $token = $this->ownerToken($shop);
        $ownerId = $this->owner->id;

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/shop/users/{$ownerId}")
            ->assertStatus(422);
    }
}
