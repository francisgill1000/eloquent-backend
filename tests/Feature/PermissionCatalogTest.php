<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_all_catalog_permissions_globally(): void
    {
        (new PermissionSeeder())->run();

        foreach (PermissionCatalog::all() as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name, 'guard_name' => 'web']);
        }

        // The table mirrors the catalog exactly (seeder prunes extras).
        $this->assertSame(count(PermissionCatalog::all()), Permission::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        (new PermissionSeeder())->run();
        (new PermissionSeeder())->run();

        $this->assertSame(count(PermissionCatalog::all()), Permission::count());
    }

    public function test_seeder_prunes_permissions_removed_from_the_catalog(): void
    {
        // A stray permission from a previous catalog (e.g. the removed
        // Services/Staff/Working Hours ones) must be cleaned up on re-seed.
        Permission::create(['name' => 'staff.manage', 'guard_name' => 'web']);

        (new PermissionSeeder())->run();

        $this->assertDatabaseMissing('permissions', ['name' => 'staff.manage']);
        $this->assertSame(count(PermissionCatalog::all()), Permission::count());
    }

    public function test_hunt_group_exists_with_four_permissions(): void
    {
        $groups = PermissionCatalog::grouped();
        $this->assertArrayHasKey('hunt', $groups);
        $this->assertSame('leads', $groups['hunt']['module']);
        $this->assertSame(
            ['leads.view', 'leads.search', 'leads.manage', 'leads.purchase'],
            array_keys($groups['hunt']['permissions']),
        );
    }

    public function test_forshop_bookings_only_hides_business_hunt(): void
    {
        $shop = Shop::factory()->create(['modules' => ['bookings']]);
        $keys = array_keys(PermissionCatalog::forShop($shop));

        $this->assertContains('dashboard', $keys);
        $this->assertContains('bookings', $keys);
        $this->assertContains('customers', $keys);
        $this->assertNotContains('hunt', $keys);
        // Shared groups always show.
        $this->assertContains('assistant', $keys);
        $this->assertContains('access', $keys);
        $this->assertContains('settings', $keys);
        // The stripped shape is { label, permissions } — no module key leaks.
        $this->assertArrayNotHasKey('module', PermissionCatalog::forShop($shop)['bookings']);
    }

    public function test_forshop_leads_only_shows_only_hunt_and_shared(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $keys = array_keys(PermissionCatalog::forShop($shop));

        $this->assertContains('hunt', $keys);
        $this->assertNotContains('dashboard', $keys);
        $this->assertNotContains('bookings', $keys);
        $this->assertNotContains('customers', $keys);
        $this->assertContains('assistant', $keys);
        $this->assertContains('access', $keys);
        $this->assertContains('settings', $keys);
    }

    public function test_forshop_both_modules_and_master_see_everything(): void
    {
        $both = Shop::factory()->create(['modules' => ['bookings', 'leads']]);
        $this->assertSame(array_keys(PermissionCatalog::grouped()), array_keys(PermissionCatalog::forShop($both)));

        $master = Shop::factory()->create(['is_master' => true, 'modules' => ['bookings']]);
        $this->assertSame(array_keys(PermissionCatalog::grouped()), array_keys(PermissionCatalog::forShop($master)));
    }
}
