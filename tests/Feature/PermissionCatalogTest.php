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

        $this->assertGreaterThanOrEqual(20, Permission::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        (new PermissionSeeder())->run();
        (new PermissionSeeder())->run();

        $this->assertSame(count(PermissionCatalog::all()), Permission::count());
    }
}
