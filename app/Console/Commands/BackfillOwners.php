<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Ensures every shop has an Owner role (all permissions) and an Owner
 * ShopUser seeded from the shop's existing PIN. Idempotent.
 */
class BackfillOwners extends Command
{
    protected $signature = 'rbac:backfill-owners';

    protected $description = 'Create an Owner role + user for every shop from its existing PIN';

    public function handle(): int
    {
        // Make sure the global permission catalog exists first.
        (new PermissionSeeder())->run();

        Shop::query()->chunkById(200, function ($shops) {
            foreach ($shops as $shop) {
                setPermissionsTeamId($shop->id);

                $owner = Role::firstOrCreate([
                    'name' => 'Owner',
                    'guard_name' => 'web',
                    'team_id' => $shop->id,
                ]);
                $owner->syncPermissions(PermissionCatalog::all());

                $user = ShopUser::firstOrCreate(
                    ['shop_id' => $shop->id, 'login_pin' => $shop->pin],
                    ['name' => $shop->name ?: 'Owner', 'is_active' => true],
                );

                if (!$user->hasRole('Owner')) {
                    $user->assignRole($owner);
                }
            }
        });

        $this->info('Owner roles and users backfilled.');

        return self::SUCCESS;
    }
}
