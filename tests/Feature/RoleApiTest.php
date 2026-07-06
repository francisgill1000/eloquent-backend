<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingOwner(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);

        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();

        return $new->plainTextToken;
    }

    public function test_owner_can_create_and_list_roles_scoped_to_shop(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        $token = $this->actingOwner($shop);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/shop/roles', ['name' => 'Cashier', 'permissions' => ['bookings.view', 'bookings.create']])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Cashier');

        $list = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/shop/roles');
        $list->assertOk();
        $names = collect($list->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Cashier'));
        $this->assertTrue($names->contains('Owner'));
    }

    public function test_roles_are_tenant_isolated(): void
    {
        (new PermissionSeeder())->run();
        $shopA = Shop::factory()->trialing()->create();
        $shopB = Shop::factory()->trialing()->create();

        setPermissionsTeamId($shopB->id);
        $foreign = Role::create(['name' => 'BOnly', 'guard_name' => 'web', 'team_id' => $shopB->id]);

        $token = $this->actingOwner($shopA);

        $list = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/shop/roles');
        $this->assertFalse(collect($list->json('data'))->pluck('name')->contains('BOnly'));

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/shop/roles/{$foreign->id}", ['name' => 'x', 'permissions' => []])
            ->assertStatus(404);
    }

    public function test_owner_role_cannot_be_edited_or_deleted(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        $token = $this->actingOwner($shop);
        $owner = Role::where('team_id', $shop->id)->where('name', 'Owner')->first();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/shop/roles/{$owner->id}", ['name' => 'Boss', 'permissions' => []])
            ->assertStatus(403);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/shop/roles/{$owner->id}")
            ->assertStatus(403);
    }

    public function test_unknown_permission_is_rejected(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->trialing()->create();
        $token = $this->actingOwner($shop);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/shop/roles', ['name' => 'Bad', 'permissions' => ['made.up']])
            ->assertStatus(422);
    }
}
