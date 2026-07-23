<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Support\Rbac;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lead assignment & the agent flow: ownership, isolation (an agent sees only
 * their own leads) and distribution (bulk hand-out + round-robin).
 */
class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /** An Owner-role user for the shop. */
    private function owner(Shop $shop): ShopUser
    {
        setPermissionsTeamId($shop->id);
        $role = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);

        return $u;
    }

    /** A non-owner user holding exactly $perms. */
    private function agent(Shop $shop, array $perms = ['leads.view', 'leads.manage']): ShopUser
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Agent-'.uniqid(), 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions($perms);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_leads_carry_an_assignee_and_shops_carry_rotation_state(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);

        $lead = Lead::create([
            'shop_id' => $shop->id,
            'name' => 'Acme Salon',
            'status' => 'new',
            'assigned_to_id' => $user->id,
            'assigned_at' => now(),
        ]);

        $this->assertSame($user->id, $lead->fresh()->assigned_to_id);
        $this->assertSame($user->id, $lead->fresh()->assignedTo->id);

        $shop->update(['lead_auto_assign' => true, 'lead_assign_cursor' => $user->id]);
        $this->assertTrue($shop->fresh()->lead_auto_assign);
        $this->assertSame($user->id, $shop->fresh()->lead_assign_cursor);
    }

    public function test_sees_all_leads_is_true_for_owner_null_and_view_all_holders(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $this->assertTrue(Rbac::seesAllLeads(null));
        $this->assertTrue(Rbac::seesAllLeads($this->owner($shop)));
        $this->assertTrue(Rbac::seesAllLeads($this->agent($shop, ['leads.view', 'leads.view_all'])));
        $this->assertFalse(Rbac::seesAllLeads($this->agent($shop, ['leads.view'])));
    }

    public function test_backfill_grants_view_all_to_existing_roles_holding_leads_view(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        // A role that predates this feature: has leads.view, not leads.view_all.
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Legacy', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions(['leads.view']);
        $this->assertFalse($role->hasPermissionTo('leads.view_all'));

        \Illuminate\Support\Facades\Artisan::call('leads:backfill-view-all');

        $this->assertTrue($role->fresh()->hasPermissionTo('leads.view_all'));
    }
}
