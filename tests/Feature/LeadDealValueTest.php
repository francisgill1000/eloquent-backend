<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadDealValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_total_is_amount_for_one_off(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'A', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off',
        ]);

        $this->assertSame(500.0, $lead->deal_total);
        $this->assertArrayHasKey('deal_total', $lead->fresh()->toArray());
    }

    public function test_deal_total_multiplies_monthly_by_term_for_recurring(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'B', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6,
        ]);

        $this->assertSame(1800.0, $lead->deal_total);
    }

    public function test_deal_total_is_null_without_amount(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'C', 'status' => 'won']);

        $this->assertNull($lead->deal_total);
    }

    public function test_deal_total_is_null_for_recurring_without_a_term(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'D', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring',
        ]);

        $this->assertNull($lead->deal_total);
    }

    public function test_deal_won_at_casts_to_datetime(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'E', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $lead->fresh()->deal_won_at);
    }
}
