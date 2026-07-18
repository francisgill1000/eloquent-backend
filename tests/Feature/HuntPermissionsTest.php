<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WS2: Business Hunt permissions (leads.*) gate the Lead routes, and the roles
 * screen is reachable + module-filtered for a Hunt-only shop (no Lens sub).
 */
class HuntPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    /** @param array<int,string> $perms */
    private function staffToken(Shop $shop, array $perms): string
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Staff-'.uniqid(), 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions($perms);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    private function authGet(string $token, string $url)
    {
        return $this->withHeaders(['Authorization' => "Bearer $token", 'Accept' => 'application/json'])->getJson($url);
    }

    public function test_leads_index_requires_leads_view(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $this->authGet($this->staffToken($shop, []), '/api/shop/leads')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->authGet($this->staffToken($shop, ['leads.view']), '/api/shop/leads')->assertOk();
    }

    public function test_leads_search_is_blocked_without_leads_search_permission(): void
    {
        // Missing permission is rejected by middleware BEFORE the controller, so
        // this never triggers a real (credit-spending) search.
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $this->authGet($this->staffToken($shop, ['leads.view']), '/api/shop/leads/search?category=salon&area=Dubai')
            ->assertStatus(403);
    }

    public function test_owner_bypasses_lead_permissions(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $this->authGet($this->ownerToken($shop), '/api/shop/leads')->assertOk();
    }

    public function test_permissions_endpoint_is_module_filtered_for_a_hunt_shop(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $res = $this->authGet($this->ownerToken($shop), '/api/shop/permissions')->assertOk();
        $groups = $res->json('data');

        $this->assertArrayHasKey('hunt', $groups);
        $this->assertArrayNotHasKey('bookings', $groups);
        $this->assertArrayNotHasKey('dashboard', $groups);
        $this->assertArrayNotHasKey('customers', $groups);
        // Shared always present.
        $this->assertArrayHasKey('access', $groups);
        $this->assertArrayHasKey('settings', $groups);
    }

    public function test_hunt_only_shop_without_subscription_can_reach_roles(): void
    {
        // No ->trialing(): this shop has NO active Lens subscription. Before WS2 the
        // RBAC routes were behind subscription.active and returned 402.
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $this->authGet($this->ownerToken($shop), '/api/shop/roles')->assertOk();
    }
}
