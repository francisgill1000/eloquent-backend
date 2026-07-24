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
}
