<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs the GLOBAL (team_id = null) spatie permissions to exactly match the
 * catalog: creates any that are missing and prunes any that were removed.
 * Idempotent — safe to run on every deploy. Pruning cascades to
 * role_has_permissions (see the permission-tables migration), so a role that
 * held a since-removed permission loses it automatically.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Global permissions live outside any team.
        setPermissionsTeamId(null);

        $catalog = PermissionCatalog::all();

        foreach ($catalog as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        // Prune anything no longer in the catalog so the table stays an exact mirror.
        Permission::where('guard_name', 'web')
            ->whereNotIn('name', $catalog)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
