<?php

namespace Tests\Feature;

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
}
