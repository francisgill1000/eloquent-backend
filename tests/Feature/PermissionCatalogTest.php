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
        // A stray permission that is no longer in the catalog must be cleaned up
        // on re-seed.
        Permission::create(['name' => 'obsolete.legacy', 'guard_name' => 'web']);

        (new PermissionSeeder())->run();

        $this->assertDatabaseMissing('permissions', ['name' => 'obsolete.legacy']);
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
        // The stripped shape is { label, section, permissions } — no module key
        // leaks, but the nav `section` tag is passed through for UI nesting.
        $bookings = PermissionCatalog::forShop($shop)['bookings'];
        $this->assertArrayNotHasKey('module', $bookings);
        $this->assertArrayHasKey('section', $bookings);
        $this->assertNull($bookings['section']);
    }

    public function test_assistant_is_split_by_nav_section(): void
    {
        $groups = PermissionCatalog::grouped();

        // "Use the assistant" powers the Home page → top-level (section null).
        $this->assertNull($groups['assistant']['section']);
        $this->assertSame(['assistant.use'], array_keys($groups['assistant']['permissions']));

        // "Configure the assistant" is a Settings page → nested under Settings.
        $this->assertSame('Settings', $groups['assistant_config']['section']);
        $this->assertSame(['assistant.manage'], array_keys($groups['assistant_config']['permissions']));

        // Both assistant permissions still exist exactly once in the flat list.
        $this->assertSame(['assistant.use'], array_values(array_filter(PermissionCatalog::all(), fn ($p) => $p === 'assistant.use')));
        $this->assertContains('assistant.manage', PermissionCatalog::all());
    }

    public function test_settings_pages_are_tagged_with_the_settings_section(): void
    {
        $groups = PermissionCatalog::grouped();

        // Pages reached through the Settings container nest under it.
        foreach (['catalog', 'staff', 'hours', 'assistant_config', 'access', 'settings'] as $key) {
            $this->assertSame('Settings', $groups[$key]['section'], "$key should be in the Settings section");
        }

        // Top-level menu destinations are not sectioned.
        foreach (['dashboard', 'bookings', 'customers', 'hunt', 'assistant'] as $key) {
            $this->assertNull($groups[$key]['section'], "$key should be top-level");
        }
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
