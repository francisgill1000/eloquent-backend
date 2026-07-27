<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `home.view` was introduced after roles already existed in production. Home is
 * the screen every user lands on after login, so shipping a required permission
 * without backfilling it would lock every non-owner user out of their own
 * landing page on deploy day. Granting it to every existing role keeps
 * behaviour identical; revoking it from a role later is the deliberate act.
 *
 * Owners are unaffected either way — Rbac::userCan short-circuits for them.
 * Idempotent: safe to run on every deploy.
 */
class BackfillHomeView extends Command
{
    protected $signature = 'home:backfill-view';

    protected $description = 'Grant home.view to every existing role';

    public function handle(): int
    {
        $homeView = Permission::where('name', 'home.view')->where('guard_name', 'web')->first();
        if ($homeView === null) {
            $this->error('home.view is not seeded — run the PermissionSeeder first.');

            return self::FAILURE;
        }

        $granted = 0;
        Role::with('permissions')->chunk(100, function ($roles) use ($homeView, &$granted) {
            foreach ($roles as $role) {
                if ($role->permissions->pluck('name')->contains('home.view')) {
                    continue;
                }
                // Roles are team-scoped; align the team context with the role
                // being edited so the grant lands on the right tenant.
                setPermissionsTeamId($role->team_id);
                $role->givePermissionTo($homeView);
                $granted++;
            }
        });

        $this->info("Granted home.view to {$granted} role(s).");

        return self::SUCCESS;
    }
}
