<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnsurePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'rbac.context', 'can.perm:bookings.delete'])
            ->get('/api/_test/guarded', fn () => response()->json(['ok' => true]));
    }

    private function tokenFor(ShopUser $u): string
    {
        $new = $u->shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();

        return $new->plainTextToken;
    }

    public function test_denied_without_permission(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($u)])
            ->getJson('/api/_test/guarded')
            ->assertStatus(403);
    }

    public function test_allowed_with_permission(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Mgr', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->givePermissionTo('bookings.delete');
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($u)])
            ->getJson('/api/_test/guarded')
            ->assertOk();
    }

    public function test_unknown_permission_denies_cleanly_without_500(): void
    {
        // A route gated on a permission that no longer exists in the catalog
        // (e.g. a removed Services/Staff/Working Hours perm) must 403, not 500.
        Route::middleware(['auth:sanctum', 'rbac.context', 'can.perm:ghost.permission'])
            ->get('/api/_test/ghost', fn () => response()->json(['ok' => true]));

        (new PermissionSeeder())->run(); // catalog does not contain ghost.permission
        $shop = Shop::factory()->create();
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($u)])
            ->getJson('/api/_test/ghost')
            ->assertStatus(403);
    }

    public function test_owner_bypasses(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create();
        setPermissionsTeamId($shop->id);
        $owner = Role::create(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($u)])
            ->getJson('/api/_test/guarded')
            ->assertOk();
    }
}
