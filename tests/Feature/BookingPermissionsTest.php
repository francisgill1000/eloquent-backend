<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WS3: Staff and Working Hours are grantable to sub-users. Staff writes require
 * staff.manage; the working-hours part of the shop update requires
 * working_hours.manage (profile edits are NOT gated). Owner bypasses.
 */
class BookingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);
        return $this->bind($shop, $u);
    }

    /** @param array<int,string> $perms */
    private function staffToken(Shop $shop, array $perms): string
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Staff-'.uniqid(), 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions($perms);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);
        return $this->bind($shop, $u);
    }

    private function bind(Shop $shop, ShopUser $u): string
    {
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    private function as(string $token)
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => "Bearer $token", 'Accept' => 'application/json']);
    }

    public function test_staff_create_requires_staff_manage(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['bookings']]);

        $this->as($this->staffToken($shop, []))
            ->postJson("/api/shops/{$shop->id}/staff", ['name' => 'Sara'])->assertStatus(403);

        $this->as($this->staffToken($shop, ['staff.manage']))
            ->postJson("/api/shops/{$shop->id}/staff", ['name' => 'Sara'])->assertStatus(201);

        $this->as($this->ownerToken($shop))
            ->postJson("/api/shops/{$shop->id}/staff", ['name' => 'Owner Add'])->assertStatus(201);
    }

    public function test_working_hours_update_requires_working_hours_manage(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['bookings']]);
        $hours = [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']];

        $this->as($this->staffToken($shop, []))
            ->putJson("/api/shops/{$shop->id}", ['working_hours' => $hours])->assertStatus(403);

        $this->as($this->staffToken($shop, ['working_hours.manage']))
            ->putJson("/api/shops/{$shop->id}", ['working_hours' => $hours])->assertOk();
    }

    public function test_profile_edit_is_not_blocked_by_missing_working_hours_permission(): void
    {
        // WS3 only gates the working_hours part — a profile-only edit still works
        // for a user without working_hours.manage.
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['bookings']]);

        $this->as($this->staffToken($shop, []))
            ->putJson("/api/shops/{$shop->id}", ['name' => 'Renamed Shop'])->assertOk();
    }

    public function test_forshop_bookings_shop_includes_the_new_groups(): void
    {
        $shop = Shop::factory()->create(['modules' => ['bookings']]);
        $keys = array_keys(PermissionCatalog::forShop($shop));

        foreach (['catalog', 'staff', 'hours'] as $group) {
            $this->assertContains($group, $keys);
        }
    }

    public function test_forshop_leads_shop_excludes_the_bookings_catalog_groups(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $keys = array_keys(PermissionCatalog::forShop($shop));

        foreach (['catalog', 'staff', 'hours'] as $group) {
            $this->assertNotContains($group, $keys);
        }
    }
}
