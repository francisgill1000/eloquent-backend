<?php

namespace Tests\Feature;

use App\Models\AiSummary;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSummaryHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'S' . $code, 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    private function actingOwner(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = \App\Models\ShopUser::factory()->create(['shop_id' => $shop->id]);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    private function seedWeek(int $shopId, string $from, string $to): void
    {
        AiSummary::create([
            'shop_id' => $shopId, 'period_type' => 'week', 'summary_date' => $to,
            'period_from' => $from, 'period_to' => $to,
            'summary' => "Week {$from}", 'patterns' => ['p'], 'recommendations' => ['r'],
        ]);
    }

    public function test_history_returns_only_the_requested_type_newest_first(): void
    {
        $shop = $this->shop('7301');
        $this->seedWeek($shop->id, '2026-06-01', '2026-06-07');
        $this->seedWeek($shop->id, '2026-06-08', '2026-06-14');
        // A month row must NOT appear in a week query.
        AiSummary::create(['shop_id' => $shop->id, 'period_type' => 'month', 'summary_date' => '2026-06-30',
            'period_from' => '2026-06-01', 'period_to' => '2026-06-30', 'summary' => 'M', 'patterns' => [], 'recommendations' => []]);

        $token = $this->actingOwner($shop);
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/shop/reports/ai-summaries?period_type=week');

        $res->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.period_from', '2026-06-08') // newest first
            ->assertJsonPath('has_more', false);
    }

    public function test_history_is_tenant_scoped(): void
    {
        $a = $this->shop('7302');
        $b = $this->shop('7303');
        $this->seedWeek($b->id, '2026-06-01', '2026-06-07');

        // Authenticate as shop A; only A's summaries (none) should be returned.
        $token = $this->actingOwner($a);
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/shop/reports/ai-summaries?period_type=week');
        $res->assertOk()->assertJsonCount(0, 'data');
    }
}
