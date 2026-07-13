<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Reports\ReportsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsAggregatorHuntTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'H' . $code, 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
    }

    public function test_hunt_summary_reports_pipeline_movement_and_activity(): void
    {
        $shop = $this->shop('8001');

        // Pipeline snapshot: 3 new, 1 won.
        Lead::create(['shop_id' => $shop->id, 'name' => 'A', 'status' => 'new']);
        Lead::create(['shop_id' => $shop->id, 'name' => 'B', 'status' => 'new']);
        Lead::create(['shop_id' => $shop->id, 'name' => 'C', 'status' => 'new']);
        $won = Lead::create(['shop_id' => $shop->id, 'name' => 'D', 'status' => 'won']);

        // Movement in period: two →sent, one →won.
        $won->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'demo', 'to' => 'won']]);
        Lead::find($won->id); // no-op, keep style
        foreach (['A', 'B'] as $n) {
            $lead = Lead::where('shop_id', $shop->id)->where('name', $n)->first();
            $lead->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'new', 'to' => 'sent']]);
        }

        // A non-status activity must be ignored.
        $won->activities()->create(['type' => 'contacted', 'payload' => ['channel' => 'whatsapp']]);

        // Credits used (2 search debits) + a grant that must NOT count.
        DB::table('hunt_credit_transactions')->insert([
            ['shop_id' => $shop->id, 'amount' => -1, 'reason' => 'search', 'balance_after' => 9, 'created_at' => now(), 'updated_at' => now()],
            ['shop_id' => $shop->id, 'amount' => -1, 'reason' => 'search', 'balance_after' => 8, 'created_at' => now(), 'updated_at' => now()],
            ['shop_id' => $shop->id, 'amount' => 50, 'reason' => 'grant', 'balance_after' => 58, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Two searches logged.
        DB::table('lead_search_logs')->insert([
            ['shop_id' => $shop->id, 'query' => 'gyms', 'created_at' => now()],
            ['shop_id' => $shop->id, 'query' => 'hotels', 'created_at' => now()],
        ]);

        $out = app(ReportsAggregator::class)->huntSummary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(4, $out['new_leads']);       // all 4 leads created this month
        $this->assertSame(3, $out['pipeline']['new']);
        $this->assertSame(1, $out['pipeline']['won']);
        $this->assertSame(4, $out['total_leads']);
        $this->assertSame(2, $out['moved']['sent']);
        $this->assertSame(1, $out['moved']['won']);
        $this->assertSame(1, $out['won']);
        $this->assertSame(2, $out['credits_used']);    // abs of the two -1 search debits
        $this->assertSame(2, $out['searches']);
    }

    public function test_hunt_summary_is_tenant_scoped(): void
    {
        $a = $this->shop('8002');
        $b = $this->shop('8003');
        Lead::create(['shop_id' => $b->id, 'name' => 'other', 'status' => 'new'])
            ->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'new', 'to' => 'won']]);

        $out = app(ReportsAggregator::class)->huntSummary($a->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(0, $out['new_leads']);
        $this->assertSame(0, $out['total_leads']);
        $this->assertSame(0, $out['moved']['won']);
    }

    public function test_hunt_summary_reports_won_value_split_and_mrr(): void
    {
        $shop = $this->shop('8004');

        // One-off won this period: AED 500.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'One', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now(),
        ]);
        // Recurring won this period: 300/mo × 6 = 1800 total, 300 MRR.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'Rec', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'deal_won_at' => now(),
        ]);
        // Won without an amount — counts in `won`, contributes 0 value.
        Lead::create(['shop_id' => $shop->id, 'name' => 'Blank', 'status' => 'won', 'deal_won_at' => now()]);
        // Reversed deal: was won, now passed — must be EXCLUDED from value.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'Lost', 'status' => 'pass',
            'deal_amount' => 9999, 'deal_type' => 'one_off', 'deal_won_at' => now(),
        ]);
        // Won but OUTSIDE the period (won last month) — excluded.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'Old', 'status' => 'won',
            'deal_amount' => 7777, 'deal_type' => 'one_off', 'deal_won_at' => now()->subMonthNoOverflow()->subDays(2),
        ]);

        $out = app(ReportsAggregator::class)->huntSummary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(500.0 + 1800.0, $out['won_value']);
        $this->assertSame(1800.0, $out['won_value_recurring']);
        $this->assertSame(500.0, $out['won_value_one_off']);
        $this->assertSame(300.0, $out['mrr_won']);
    }

    public function test_won_value_totals_lifetime_and_period(): void
    {
        $shop = $this->shop('8010');
        // In-period recurring + one-off, a reversed (pass) deal, and an out-of-period win.
        Lead::create(['shop_id' => $shop->id, 'name' => 'R', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'deal_won_at' => now()]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'O', 'status' => 'won', 'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now()]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Lost', 'status' => 'pass', 'deal_amount' => 9999, 'deal_type' => 'one_off', 'deal_won_at' => now()]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Old', 'status' => 'won', 'deal_amount' => 700, 'deal_type' => 'one_off', 'deal_won_at' => now()->subMonthsNoOverflow(2)]);

        $agg = app(ReportsAggregator::class);

        // Period = this month: excludes Old + Lost.
        $period = $agg->wonValueTotals($shop->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(150.0 * 6 + 500.0, $period['won_value']);
        $this->assertSame(900.0, $period['won_value_recurring']);
        $this->assertSame(500.0, $period['won_value_one_off']);
        $this->assertSame(150.0, $period['mrr_won']);
        $this->assertSame(2, $period['won_count']);

        // Lifetime: includes Old (700) but still excludes reversed Lost.
        $life = $agg->wonValueTotals($shop->id);
        $this->assertSame(900.0 + 500.0 + 700.0, $life['won_value']);
        $this->assertSame(3, $life['won_count']);
    }
}
