<?php

namespace Tests\Feature;

use App\Models\Shop;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * home.view gates the screen every user lands on after login. It was added
 * after roles already existed in production, so the deploy-day behaviour of the
 * backfill is what keeps live users out of a lockout.
 */
class BackfillHomeViewTest extends TestCase
{
    use RefreshDatabase;

    private function roleFor(Shop $shop, string $name, array $perms = []): Role
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => $name, 'guard_name' => 'web', 'team_id' => $shop->id]);
        if ($perms) {
            $role->syncPermissions($perms);
        }

        return $role;
    }

    public function test_it_grants_home_view_to_every_existing_role(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        $manager = $this->roleFor($shop, 'Manager', ['leads.view']);
        $agent = $this->roleFor($shop, 'Agent', ['leads.view', 'leads.manage']);

        $this->artisan('home:backfill-view')->assertExitCode(0);

        setPermissionsTeamId($shop->id);
        $this->assertTrue($manager->fresh()->hasPermissionTo('home.view'));
        $this->assertTrue($agent->fresh()->hasPermissionTo('home.view'));
    }

    public function test_it_grants_home_view_to_a_role_with_no_permissions_at_all(): void
    {
        // An empty role still lands somewhere on login; don't strand it.
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        $empty = $this->roleFor($shop, 'Empty');

        $this->artisan('home:backfill-view')->assertExitCode(0);

        setPermissionsTeamId($shop->id);
        $this->assertTrue($empty->fresh()->hasPermissionTo('home.view'));
    }

    public function test_it_grants_across_tenants_not_just_the_first(): void
    {
        // Roles are team-scoped; a backfill that ignores team_id silently skips
        // every shop but one.
        (new PermissionSeeder())->run();
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();
        $roleA = $this->roleFor($a, 'Staff A', ['leads.view']);
        $roleB = $this->roleFor($b, 'Staff B', ['leads.view']);

        $this->artisan('home:backfill-view')->assertExitCode(0);

        setPermissionsTeamId($a->id);
        $this->assertTrue($roleA->fresh()->hasPermissionTo('home.view'));
        setPermissionsTeamId($b->id);
        $this->assertTrue($roleB->fresh()->hasPermissionTo('home.view'));
    }

    public function test_it_is_idempotent(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        $role = $this->roleFor($shop, 'Manager', ['leads.view']);

        $this->artisan('home:backfill-view')->assertExitCode(0);
        $this->artisan('home:backfill-view')->assertExitCode(0);

        setPermissionsTeamId($shop->id);
        $this->assertSame(
            1,
            $role->fresh()->permissions->where('name', 'home.view')->count(),
        );
    }

    public function test_it_fails_cleanly_when_the_permission_is_not_seeded(): void
    {
        // The test database is seeded from DatabaseSeeder, so the unseeded state
        // has to be created deliberately: this is the deploy-order mistake of
        // running the backfill before the seeder. It must report, not crash.
        \Spatie\Permission\Models\Permission::where('name', 'home.view')
            ->where('guard_name', 'web')
            ->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->artisan('home:backfill-view')
            ->expectsOutputToContain('home.view is not seeded')
            ->assertExitCode(1);
    }
}
