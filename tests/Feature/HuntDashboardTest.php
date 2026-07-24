<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Reports\ReportsAggregator;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Business Hunt dashboard: the two new aggregates, the endpoint that
 * serves them, and the guarantee that an attention count matches the filtered
 * list its chip links to.
 */
class HuntDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** A non-owner user holding exactly $perms. */
    private function agent(Shop $shop, array $perms = ['leads.view', 'leads.manage']): ShopUser
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'R-'.uniqid(), 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions($perms);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);

        return $u;
    }

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

    public function test_hunt_daily_zero_fills_and_buckets_by_the_right_date(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        // Two leads created on day 1, none on day 2, one on day 3.
        Lead::factory()->count(2)->create(['shop_id' => $shop->id, 'created_at' => '2026-07-01 09:00:00']);
        Lead::factory()->create(['shop_id' => $shop->id, 'created_at' => '2026-07-03 09:00:00']);

        // A win on day 3, created earlier — proves wins bucket by deal_won_at,
        // not created_at.
        Lead::factory()->create([
            'shop_id' => $shop->id, 'status' => 'won',
            'created_at' => '2026-06-01 09:00:00',
            'deal_won_at' => '2026-07-03 15:00:00',
            'deal_amount' => 250, 'deal_type' => 'one_off',
        ]);

        $out = app(ReportsAggregator::class)->huntDaily(
            $shop->id,
            \Carbon\Carbon::parse('2026-07-01'),
            \Carbon\Carbon::parse('2026-07-03'),
        );

        $this->assertCount(3, $out);
        $this->assertSame(
            [
                ['date' => '2026-07-01', 'leads' => 2, 'won' => 0, 'won_value' => 0.0],
                ['date' => '2026-07-02', 'leads' => 0, 'won' => 0, 'won_value' => 0.0],
                // The won lead was created back on 06-01, so it adds to `won`
                // here but not to `leads` — one lead was created on 07-03.
                ['date' => '2026-07-03', 'leads' => 1, 'won' => 1, 'won_value' => 250.0],
            ],
            $out,
        );
    }

    public function test_hunt_daily_is_tenant_scoped(): void
    {
        $a = Shop::factory()->create(['modules' => ['leads']]);
        $b = Shop::factory()->create(['modules' => ['leads']]);
        Lead::factory()->create(['shop_id' => $b->id, 'created_at' => '2026-07-01 09:00:00']);

        $out = app(ReportsAggregator::class)->huntDaily(
            $a->id,
            \Carbon\Carbon::parse('2026-07-01'),
            \Carbon\Carbon::parse('2026-07-01'),
        );

        $this->assertSame([['date' => '2026-07-01', 'leads' => 0, 'won' => 0, 'won_value' => 0.0]], $out);
    }

    public function test_hunt_daily_shows_an_agent_only_their_own_leads(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $agent = $this->agent($shop, ['leads.view']); // no leads.view_all

        Lead::factory()->create(['shop_id' => $shop->id, 'created_at' => '2026-07-01 09:00:00', 'assigned_to_id' => $agent->id]);
        Lead::factory()->create(['shop_id' => $shop->id, 'created_at' => '2026-07-01 09:00:00']); // someone else's

        \App\Support\CurrentShopUser::set($agent);
        $out = app(ReportsAggregator::class)->huntDaily(
            $shop->id,
            \Carbon\Carbon::parse('2026-07-01'),
            \Carbon\Carbon::parse('2026-07-01'),
        );

        $this->assertSame(1, $out[0]['leads']);
    }

    public function test_hunt_daily_caps_at_366_days(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $out = app(ReportsAggregator::class)->huntDaily(
            $shop->id,
            \Carbon\Carbon::parse('2020-01-01'),
            \Carbon\Carbon::parse('2026-01-01'),
        );

        $this->assertCount(366, $out);
    }

    /** Seeds one lead in each attention bucket plus decoys. Returns the shop. */
    private function attentionFixture(): Shop
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $base = ['shop_id' => $shop->id, 'assigned_to_id' => null];

        // Overdue follow-up.
        Lead::factory()->create($base + [
            'status' => 'followup', 'next_followup_at' => now()->subDays(3),
            'last_contacted_at' => now()->subDays(3),
        ]);
        // Due today.
        Lead::factory()->create($base + [
            'status' => 'sent', 'next_followup_at' => now()->setTime(18, 0),
            'last_contacted_at' => now(),
        ]);
        // Decoy: won, with an overdue follow-up date left behind. Not actionable.
        Lead::factory()->create($base + [
            'status' => 'won', 'next_followup_at' => now()->subDays(9),
            'deal_won_at' => now(), 'deal_amount' => 100, 'deal_type' => 'one_off',
        ]);
        // Stale: worked, then dropped for 20 days.
        Lead::factory()->create($base + [
            'status' => 'replied', 'last_contacted_at' => now()->subDays(20),
        ]);
        // Decoy: `new` and never contacted. Not stale — it was never worked.
        Lead::factory()->create($base + ['status' => 'new', 'last_contacted_at' => null]);

        return $shop;
    }

    public function test_hunt_attention_counts_each_bucket(): void
    {
        $shop = $this->attentionFixture();

        $out = app(ReportsAggregator::class)->huntAttention($shop->id);

        $this->assertSame(1, $out['followups_overdue'], 'the won lead must not count');
        $this->assertSame(1, $out['followups_today']);
        $this->assertSame(1, $out['stale'], 'a never-worked `new` lead is not stale');
        $this->assertSame(5, $out['unassigned']);
    }

    public function test_hunt_attention_is_tenant_scoped(): void
    {
        $this->attentionFixture();
        $other = Shop::factory()->create(['modules' => ['leads']]);

        $out = app(ReportsAggregator::class)->huntAttention($other->id);

        $this->assertSame(
            ['followups_overdue' => 0, 'followups_today' => 0, 'stale' => 0, 'unassigned' => 0],
            $out,
        );
    }

    public function test_hunt_attention_shows_an_agent_only_their_own_and_never_unassigned(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $agent = $this->agent($shop, ['leads.view']); // no leads.view_all

        Lead::factory()->create([
            'shop_id' => $shop->id, 'assigned_to_id' => $agent->id,
            'status' => 'followup', 'next_followup_at' => now()->subDay(),
        ]);
        Lead::factory()->create([
            'shop_id' => $shop->id, 'assigned_to_id' => null,
            'status' => 'followup', 'next_followup_at' => now()->subDay(),
        ]);

        \App\Support\CurrentShopUser::set($agent);
        $out = app(ReportsAggregator::class)->huntAttention($shop->id);

        $this->assertSame(1, $out['followups_overdue']);
        // AssignedLeadScope rewrites the query to assigned_to_id = <agent>, so
        // "unassigned" is unreachable for them by construction.
        $this->assertSame(0, $out['unassigned']);
    }

    public function test_index_filters_match_the_attention_counts_exactly(): void
    {
        (new PermissionSeeder())->run();
        $shop = $this->attentionFixture();
        $this->startTrial($shop);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all']);
        $token = $this->tokenFor($shop, $manager);

        $counts = app(ReportsAggregator::class)->huntAttention($shop->id);

        $overdue = $this->authJson($token, 'GET', '/api/shop/leads?followups=overdue');
        $overdue->assertOk();
        $this->assertCount($counts['followups_overdue'], $overdue->json('data'));

        $today = $this->authJson($token, 'GET', '/api/shop/leads?followups=today');
        $today->assertOk();
        $this->assertCount($counts['followups_today'], $today->json('data'));

        $stale = $this->authJson($token, 'GET', '/api/shop/leads?stale=1');
        $stale->assertOk();
        $this->assertCount($counts['stale'], $stale->json('data'));

        $unassigned = $this->authJson($token, 'GET', '/api/shop/leads?assigned_to=unassigned');
        $unassigned->assertOk();
        $this->assertCount($counts['unassigned'], $unassigned->json('data'));
    }

    public function test_the_legacy_due_filter_still_omits_demo_stage(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $this->startTrial($shop);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all']);
        $token = $this->tokenFor($shop, $manager);

        Lead::factory()->create([
            'shop_id' => $shop->id, 'status' => 'demo',
            'next_followup_at' => now()->subDays(2),
        ]);

        // Pinned, not endorsed: `due` restricts to sent/followup/replied, so a
        // demo-stage lead with an overdue follow-up is invisible to it. The new
        // `overdue` filter does include it. Changing `due` is out of scope.
        $due = $this->authJson($token, 'GET', '/api/shop/leads?followups=due');
        $this->assertCount(0, $due->json('data'));

        $overdue = $this->authJson($token, 'GET', '/api/shop/leads?followups=overdue');
        $this->assertCount(1, $overdue->json('data'));
    }
}
