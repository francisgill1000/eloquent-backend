<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every catalog permission as a GLOBAL (team_id = null) spatie
 * permission. Idempotent — safe to run on every deploy.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Global permissions live outside any team.
        setPermissionsTeamId(null);

        foreach (PermissionCatalog::all() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
