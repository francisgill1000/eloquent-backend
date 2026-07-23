<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Every lead predating this feature is unassigned, so switching the global
 * scope on would blank the Hunt screen for every non-owner user in every shop.
 * Granting leads.view_all to each role that already holds leads.view keeps
 * behaviour identical on deploy day. Removing it from a role later is the
 * deliberate act that turns an employee into an agent. Idempotent.
 */
class BackfillLeadsViewAll extends Command
{
    protected $signature = 'leads:backfill-view-all';

    protected $description = 'Grant leads.view_all to every role that already holds leads.view';

    public function handle(): int
    {
        $viewAll = Permission::where('name', 'leads.view_all')->where('guard_name', 'web')->first();
        if ($viewAll === null) {
            $this->error('leads.view_all is not seeded — run the PermissionSeeder first.');

            return self::FAILURE;
        }

        $granted = 0;
        Role::with('permissions')->chunk(100, function ($roles) use ($viewAll, &$granted) {
            foreach ($roles as $role) {
                $names = $role->permissions->pluck('name');
                if ($names->contains('leads.view') && ! $names->contains('leads.view_all')) {
                    // Roles are team-scoped; align the team context with the role
                    // being edited so the grant lands on the right tenant.
                    setPermissionsTeamId($role->team_id);
                    $role->givePermissionTo($viewAll);
                    $granted++;
                }
            }
        });

        $this->info("Granted leads.view_all to {$granted} role(s).");

        return self::SUCCESS;
    }
}
