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

    // --- Layer 2: isolation ----------------------------------------------

    public function test_an_agent_sees_only_their_own_leads(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);
        $b = $this->agent($shop);

        $mine = Lead::create(['shop_id' => $shop->id, 'name' => 'Mine', 'status' => 'new', 'assigned_to_id' => $a->id]);
        $theirs = Lead::create(['shop_id' => $shop->id, 'name' => 'Theirs', 'status' => 'new', 'assigned_to_id' => $b->id]);
        $pool = Lead::create(['shop_id' => $shop->id, 'name' => 'Pool', 'status' => 'new']);

        \App\Support\CurrentShopUser::set($a);

        $this->assertSame(['Mine'], Lead::forShop($shop->id)->pluck('name')->all());
        $this->assertSame($mine->id, Lead::find($mine->id)?->id);
        $this->assertNull(Lead::find($theirs->id));
        $this->assertNull(Lead::find($pool->id));
    }

    public function test_owner_and_view_all_holders_see_every_lead_including_unassigned(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);

        Lead::create(['shop_id' => $shop->id, 'name' => 'Mine', 'status' => 'new', 'assigned_to_id' => $a->id]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Pool', 'status' => 'new']);

        \App\Support\CurrentShopUser::set($this->owner($shop));
        $this->assertCount(2, Lead::forShop($shop->id)->get());

        \App\Support\CurrentShopUser::set($this->agent($shop, ['leads.view', 'leads.view_all']));
        $this->assertCount(2, Lead::forShop($shop->id)->get());

        // Untagged (legacy) session is owner-equivalent throughout Rbac.
        \App\Support\CurrentShopUser::set(null);
        $this->assertCount(2, Lead::forShop($shop->id)->get());
    }

    public function test_importer_updates_a_lead_owned_by_another_agent_instead_of_500ing(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);
        $b = $this->agent($shop);

        $existing = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Old Name', 'status' => 'new',
            'external_ref' => 'place-123', 'assigned_to_id' => $b->id,
        ]);

        \App\Support\CurrentShopUser::set($a);

        $out = app(\App\Services\Leads\LeadImporter::class)
            ->import($shop, [['name' => 'New Name', 'external_ref' => 'place-123']]);

        $this->assertSame(0, $out['created']);
        $this->assertSame('New Name', $existing->fresh()->name);
        // Ownership is not stolen by a re-save.
        $this->assertSame($b->id, $existing->fresh()->assigned_to_id);
    }

    public function test_an_agent_saving_search_results_keeps_them(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);

        \App\Support\CurrentShopUser::set($a);

        $out = app(\App\Services\Leads\LeadImporter::class)->import($shop, [
            ['name' => 'Found One', 'external_ref' => 'p1'],
            ['name' => 'Found Two'],
        ], 'my pipeline');

        $this->assertSame(2, $out['created']);
        // Both are visible to the agent that found them — the whole point.
        $this->assertCount(2, Lead::forShop($shop->id)->get());
        foreach ($out['saved'] as $lead) {
            $this->assertSame($a->id, $lead->fresh()->assigned_to_id);
            $this->assertNotNull($lead->fresh()->assigned_at);
        }
    }

    public function test_an_owner_saving_leaves_them_unassigned(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        \App\Support\CurrentShopUser::set($this->owner($shop));

        $out = app(\App\Services\Leads\LeadImporter::class)
            ->import($shop, [['name' => 'Pool Lead']], 'pipeline');

        $this->assertNull($out['saved'][0]->fresh()->assigned_to_id);
    }

    // --- Layer 1: ownership via the API ----------------------------------

    private function tokenFor(Shop $shop, ShopUser $user): string
    {
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return $new->plainTextToken;
    }

    private function authJson(string $token, string $method, string $url, array $body = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer $token", 'Accept' => 'application/json'])
            ->json($method, $url, $body);
    }

    public function test_assigning_a_lead_records_owner_and_activity(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new']);

        $this->authJson($this->tokenFor($shop, $manager), 'PATCH', "/api/shop/leads/{$lead->id}/assign", [
            'assigned_to_id' => $agent->id,
        ])->assertOk();

        $this->assertSame($agent->id, $lead->fresh()->assigned_to_id);
        $this->assertNotNull($lead->fresh()->assigned_at);

        $activity = \App\Models\LeadActivity::where('lead_id', $lead->id)->where('type', 'assigned')->first();
        $this->assertNotNull($activity);
        $this->assertSame($agent->id, $activity->payload['to_id']);
        $this->assertSame($manager->id, $activity->user_id);
    }

    public function test_assigning_requires_the_assign_permission(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $agent = $this->agent($shop);
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new', 'assigned_to_id' => $agent->id]);

        $this->authJson($this->tokenFor($shop, $agent), 'PATCH', "/api/shop/leads/{$lead->id}/assign", [
            'assigned_to_id' => $agent->id,
        ])->assertStatus(403);
    }

    public function test_null_unassigns_and_a_foreign_or_inactive_user_is_rejected(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $other = Shop::factory()->create(['modules' => ['leads']]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);
        $outsider = ShopUser::factory()->create(['shop_id' => $other->id]);
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new', 'assigned_to_id' => $agent->id]);

        $token = $this->tokenFor($shop, $manager);

        $this->authJson($token, 'PATCH', "/api/shop/leads/{$lead->id}/assign", ['assigned_to_id' => null])->assertOk();
        $this->assertNull($lead->fresh()->assigned_to_id);

        $this->app['auth']->forgetGuards();
        $this->authJson($token, 'PATCH', "/api/shop/leads/{$lead->id}/assign", [
            'assigned_to_id' => $outsider->id,
        ])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $inactive = ShopUser::factory()->create(['shop_id' => $shop->id, 'is_active' => false]);
        $this->authJson($token, 'PATCH', "/api/shop/leads/{$lead->id}/assign", [
            'assigned_to_id' => $inactive->id,
        ])->assertStatus(422);
    }

    public function test_bulk_assign_moves_many_leads_at_once(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);

        $ids = collect(range(1, 3))->map(fn ($i) => Lead::create([
            'shop_id' => $shop->id, 'name' => "Lead {$i}", 'status' => 'new',
        ])->id)->all();

        $this->authJson($this->tokenFor($shop, $manager), 'POST', '/api/shop/leads/assign', [
            'ids' => $ids, 'assigned_to_id' => $agent->id,
        ])->assertOk()->assertJson(['assigned' => 3]);

        foreach ($ids as $id) {
            $this->assertSame(
                $agent->id,
                Lead::withoutGlobalScope(\App\Models\Scopes\AssignedLeadScope::class)->find($id)->assigned_to_id,
            );
        }
    }

    public function test_an_agent_cannot_open_another_agents_lead(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);
        $b = $this->agent($shop);
        $theirs = Lead::create(['shop_id' => $shop->id, 'name' => 'Theirs', 'status' => 'new', 'assigned_to_id' => $b->id]);

        $this->authJson($this->tokenFor($shop, $a), 'GET', "/api/shop/leads/{$theirs->id}")
            ->assertStatus(404);
    }

    public function test_index_exposes_owner_filter_assignees_and_auto_assign_flag(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads'], 'lead_auto_assign' => true]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);

        Lead::create(['shop_id' => $shop->id, 'name' => 'Owned', 'status' => 'new', 'assigned_to_id' => $agent->id]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Pool', 'status' => 'new']);

        $token = $this->tokenFor($shop, $manager);

        $all = $this->authJson($token, 'GET', '/api/shop/leads')->assertOk()->json();
        $this->assertCount(2, $all['data']);
        $this->assertTrue($all['auto_assign']);
        $this->assertContains($agent->id, array_column($all['assignees'], 'id'));
        $owned = collect($all['data'])->firstWhere('name', 'Owned');
        $this->assertSame($agent->id, $owned['assigned_to']['id']);

        $this->app['auth']->forgetGuards();
        $pool = $this->authJson($token, 'GET', '/api/shop/leads?assigned_to=unassigned')->assertOk()->json();
        $this->assertCount(1, $pool['data']);
        $this->assertSame('Pool', $pool['data'][0]['name']);

        $this->app['auth']->forgetGuards();
        $byId = $this->authJson($token, 'GET', "/api/shop/leads?assigned_to={$agent->id}")->assertOk()->json();
        $this->assertCount(1, $byId['data']);
        $this->assertSame('Owned', $byId['data'][0]['name']);
    }

    public function test_assignees_are_withheld_from_users_who_cannot_assign(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $agent = $this->agent($shop);

        $body = $this->authJson($this->tokenFor($shop, $agent), 'GET', '/api/shop/leads')->assertOk()->json();
        $this->assertSame([], $body['assignees']);
    }
}
