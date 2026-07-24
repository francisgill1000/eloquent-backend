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

    public function test_hunt_group_exists_with_its_six_permissions(): void
    {
        $groups = PermissionCatalog::grouped();
        $this->assertArrayHasKey('hunt', $groups);
        $this->assertSame('leads', $groups['hunt']['module']);
        $this->assertSame(
            ['leads.view', 'leads.view_all', 'leads.search', 'leads.manage', 'leads.assign', 'leads.purchase'],
            array_keys($groups['hunt']['permissions']),
        );
    }

    public function test_forshop_bookings_only_hides_business_hunt(): void
    {
        $shop = Shop::factory()->create(['modules' => ['bookings']]);
        $keys = array_keys(PermissionCatalog::forShop($shop));

        // Bookings-only top-level menu rows.
        $this->assertContains('bookings', $keys);
        $this->assertContains('customers', $keys);
        $this->assertNotContains('hunt', $keys);
        // Insights/reports is a bookings Settings page.
        $this->assertContains('reports', $keys);
        // Shared menu rows always show (one per left-menu item).
        foreach (['summary', 'home', 'chats', 'profile', 'access', 'settings'] as $key) {
            $this->assertContains($key, $keys);
        }
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

        // "Use the assistant" powers the Home menu → its own top-level row.
        $this->assertNull($groups['home']['section']);
        $this->assertSame(['assistant.use'], array_keys($groups['home']['permissions']));

        // "Configure the assistant" is a Settings page → in the Settings section.
        $this->assertSame('Settings', $groups['assistant_config']['section']);
        $this->assertSame(['assistant.manage'], array_keys($groups['assistant_config']['permissions']));

        // Both assistant permissions still exist exactly once in the flat list.
        $this->assertSame(['assistant.use'], array_values(array_filter(PermissionCatalog::all(), fn ($p) => $p === 'assistant.use')));
        $this->assertContains('assistant.manage', PermissionCatalog::all());
    }

    public function test_every_top_level_menu_has_its_own_group(): void
    {
        $groups = PermissionCatalog::grouped();

        // One group per left-menu item; each is top-level (section null).
        foreach (['summary', 'home', 'chats', 'bookings', 'customers', 'hunt', 'profile'] as $key) {
            $this->assertArrayHasKey($key, $groups, "$key menu should have a permission group");
            $this->assertNull($groups[$key]['section'], "$key should be a top-level row");
        }

        // The three menus that used to piggy-back on other perms now have their own.
        $this->assertSame(['summary.view'], array_keys($groups['summary']['permissions']));
        $this->assertSame(['chats.view'], array_keys($groups['chats']['permissions']));
        $this->assertSame(['profile.view'], array_keys($groups['profile']['permissions']));
    }

    public function test_settings_pages_are_tagged_with_the_settings_section(): void
    {
        $groups = PermissionCatalog::grouped();

        // Pages reached through the Settings container collapse under it.
        foreach (['reports', 'catalog', 'staff', 'hours', 'assistant_config', 'access', 'settings'] as $key) {
            $this->assertSame('Settings', $groups[$key]['section'], "$key should be in the Settings section");
        }

        // Top-level menu rows are not sectioned.
        foreach (['summary', 'home', 'chats', 'bookings', 'customers', 'hunt', 'profile'] as $key) {
            $this->assertNull($groups[$key]['section'], "$key should be top-level");
        }
    }

    public function test_forshop_leads_only_shows_only_hunt_and_shared(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $keys = array_keys(PermissionCatalog::forShop($shop));

        $this->assertContains('hunt', $keys);
        // Bookings-only rows are hidden for a Hunt shop.
        $this->assertNotContains('reports', $keys);
        $this->assertNotContains('bookings', $keys);
        $this->assertNotContains('customers', $keys);
        // Shared menu rows still show.
        foreach (['summary', 'home', 'chats', 'profile', 'access', 'settings'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_forshop_both_modules_and_master_see_everything(): void
    {
        $both = Shop::factory()->create(['modules' => ['bookings', 'leads']]);
        $this->assertSame(array_keys(PermissionCatalog::grouped()), array_keys(PermissionCatalog::forShop($both)));

        $master = Shop::factory()->create(['is_master' => true, 'modules' => ['bookings']]);
        $this->assertSame(array_keys(PermissionCatalog::grouped()), array_keys(PermissionCatalog::forShop($master)));
    }
}
