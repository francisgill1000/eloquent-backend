<?php
namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Services\Assistant\AssistantToolRegistry;
use App\Services\Credits\Exceptions\InsufficientCredits;
use App\Services\Credits\HuntCreditService;
use App\Services\Leads\LeadSearchService;
use App\Services\Leads\SearchInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HuntAssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    private function leadsShop(): Shop
    {
        return Shop::create(['name' => 'Hunt Co', 'shop_code' => '7100', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
    }

    private function exec(Shop $shop, string $tool, array $input = []): array
    {
        return json_decode(app(AssistantToolRegistry::class)->execute($shop, $tool, $input), true);
    }

    public function test_leads_shop_exposes_hunt_read_tools(): void
    {
        $names = array_column(app(AssistantToolRegistry::class)->defs($this->leadsShop()), 'name');
        $this->assertContains('hunt_credits', $names);
        $this->assertContains('list_leads', $names);
        $this->assertContains('find_lead', $names);
        $this->assertContains('open_lead', $names);
    }

    public function test_bookings_only_shop_hides_hunt_tools(): void
    {
        $shop = Shop::create(['name' => 'B', 'shop_code' => '7101', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings']]);
        $names = array_column(app(AssistantToolRegistry::class)->defs($shop), 'name');
        $this->assertNotContains('hunt_credits', $names);
        $this->assertNotContains('search_businesses', $names);
    }

    public function test_hunt_credits_returns_balance(): void
    {
        $shop = $this->leadsShop();
        app(HuntCreditService::class)->grant($shop, 5);
        $this->assertSame(5, $this->exec($shop, 'hunt_credits')['credits']);
    }

    public function test_list_leads_returns_funnel_counts(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'A', 'status' => 'new']);
        Lead::create(['shop_id' => $shop->id, 'name' => 'B', 'status' => 'won']);
        $out = $this->exec($shop, 'list_leads');
        $this->assertSame(2, $out['total']);
        $this->assertSame(1, $out['funnel']['won']);
    }

    public function test_find_lead_returns_details(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'sent', 'phone' => '0501234567']);
        $out = $this->exec($shop, 'find_lead', ['name' => 'marina']);
        $this->assertSame('Marina Gym', $out['name']);
        $this->assertSame('sent', $out['status']);
    }

    public function test_find_lead_is_ambiguous_when_multiple_match(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Gold Gym', 'status' => 'new']);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Gold Spa', 'status' => 'new']);
        $out = $this->exec($shop, 'find_lead', ['name' => 'Gold']);
        $this->assertTrue($out['ambiguous']);
    }

    public function test_find_lead_not_found(): void
    {
        $out = $this->exec($this->leadsShop(), 'find_lead', ['name' => 'nobody']);
        $this->assertSame('not_found', $out['error']);
    }

    /** Bind non-network fakes for the search + interpreter, then resolve a fresh registry. */
    private function fakeSearch(callable $configure): void
    {
        $interp = Mockery::mock(SearchInterpreter::class);
        $interp->shouldReceive('interpret')->andReturn(['keyword' => 'gyms', 'area' => 'Dubai']);
        $this->app->instance(SearchInterpreter::class, $interp);

        $search = Mockery::mock(LeadSearchService::class);
        $configure($search);
        $this->app->instance(LeadSearchService::class, $search);
    }

    public function test_search_businesses_previews_then_confirms(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('search')->once()->andReturn([
                'results' => [['name' => 'Gym One', 'external_ref' => 'g1']],
                'from_cache' => false,
                'credits' => 4,
            ]);
        });

        $preview = $this->exec($shop, 'search_businesses', ['category' => 'gyms']);
        $this->assertTrue($preview['preview']);
        $this->assertFalse($preview['saved']);

        $done = $this->exec($shop, 'search_businesses', ['category' => 'gyms', 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $this->assertSame(1, $done['count']);
        $this->assertSame(4, $done['credits_left']);
        $this->assertSame(['Gym One'], $done['sample']);
    }

    public function test_search_businesses_relays_insufficient_credits(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('search')->andThrow(new InsufficientCredits(0, 1));
        });

        $out = $this->exec($shop, 'search_businesses', ['category' => 'gyms', 'confirmed' => true]);
        $this->assertSame('insufficient_credits', $out['error']);
        $this->assertArrayNotHasKey('done', $out);
    }

    public function test_save_leads_persists_cached_results(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('cached')->andReturn([
                ['name' => 'Gym One', 'external_ref' => 'g1', 'phone' => '0501112222'],
                ['name' => 'Gym Two', 'external_ref' => 'g2'],
            ]);
        });

        $preview = $this->exec($shop, 'save_leads', ['category' => 'gyms', 'area' => 'Dubai']);
        $this->assertTrue($preview['preview']);
        $this->assertSame(0, Lead::forShop($shop->id)->count());

        $done = $this->exec($shop, 'save_leads', ['category' => 'gyms', 'area' => 'Dubai', 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $this->assertSame(2, $done['created']);
        $this->assertSame(2, Lead::forShop($shop->id)->count());
    }

    public function test_save_leads_not_found_when_no_cache(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('cached')->andReturn(null);
        });

        $out = $this->exec($shop, 'save_leads', ['category' => 'gyms']);
        $this->assertSame('not_found', $out['error']);
    }

    public function test_update_lead_status_moves_funnel_and_logs_activity(): void
    {
        $shop = $this->leadsShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'new']);

        $preview = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won']);
        $this->assertTrue($preview['preview']);
        $this->assertSame('new', $lead->fresh()->status);

        $done = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $this->assertSame('won', $lead->fresh()->status);
        $this->assertSame(1, $lead->activities()->where('type', LeadActivity::TYPE_STATUS_CHANGE)->count());
    }

    public function test_update_lead_status_rejects_invalid_status(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'new']);
        $out = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'nonsense', 'confirmed' => true]);
        $this->assertSame('invalid_status', $out['error']);
    }

    public function test_update_lead_status_won_captures_recurring_deal(): void
    {
        $shop = $this->leadsShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'demo']);

        $preview = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'deal_term_months' => 6]);
        $this->assertTrue($preview['preview']);
        $this->assertStringContainsString('900', $preview['action']); // 150 × 6 shown

        $done = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $fresh = $lead->fresh();
        $this->assertSame('won', $fresh->status);
        $this->assertSame(150.0, $fresh->deal_amount);
        $this->assertSame(6, $fresh->deal_term_months);
        $this->assertSame(900.0, $fresh->deal_total);
        $this->assertNotNull($fresh->deal_won_at);
    }

    public function test_update_lead_status_won_requires_term_for_recurring(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'demo']);
        $out = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'confirmed' => true]);
        $this->assertSame('missing_deal_term', $out['error']);
    }

    public function test_update_lead_status_won_without_amount_still_wins(): void
    {
        $shop = $this->leadsShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'demo']);
        $done = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $this->assertSame('won', $lead->fresh()->status);
        $this->assertNull($lead->fresh()->deal_amount);
        $this->assertNotNull($lead->fresh()->deal_won_at);
    }

    public function test_search_preview_shows_interpreted_term_not_raw_input(): void
    {
        $shop = $this->leadsShop();

        $interp = Mockery::mock(SearchInterpreter::class);
        $interp->shouldReceive('interpret')->andReturn(['keyword' => 'hotels', 'area' => 'Dubai']);
        $this->app->instance(SearchInterpreter::class, $interp);

        $search = Mockery::mock(LeadSearchService::class);
        $search->shouldReceive('search')->never(); // preview must NOT search/charge
        $this->app->instance(LeadSearchService::class, $search);

        $preview = $this->exec($shop, 'search_businesses', ['category' => 'find me customers']);

        $this->assertTrue($preview['preview']);
        $this->assertStringContainsString('hotels', $preview['action']);
        $this->assertStringNotContainsString('find me customers', $preview['action']);
    }

    public function test_hunt_income_lifetime_totals(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'R', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'deal_won_at' => now()]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Lost', 'status' => 'pass', 'deal_amount' => 9999, 'deal_type' => 'one_off', 'deal_won_at' => now()]);

        $out = $this->exec($shop, 'hunt_income');
        $this->assertSame('lifetime', $out['scope']);
        $this->assertEquals(900.0, $out['won_value']);
        $this->assertEquals(150.0, $out['mrr_won']);
        $this->assertSame(1, $out['won_count']);
    }

    public function test_hunt_income_period_includes_previous(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'ThisMonth', 'status' => 'won', 'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now()]);

        $out = $this->exec($shop, 'hunt_income', ['period' => 'this_month']);
        $this->assertSame('this_month', $out['scope']);
        $this->assertEquals(500.0, $out['won_value']);
        $this->assertArrayHasKey('previous', $out);
        $this->assertArrayHasKey('range', $out);
    }

    public function test_hunt_income_rejects_unknown_period(): void
    {
        $out = $this->exec($this->leadsShop(), 'hunt_income', ['period' => 'yesterday']);
        $this->assertSame('invalid_period', $out['error']);
    }

    public function test_log_followup_records_contact_without_status_change(): void
    {
        $shop = $this->leadsShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'sent']);

        $preview = $this->exec($shop, 'log_followup', ['name' => 'marina']);
        $this->assertTrue($preview['preview']);
        $this->assertSame(0, $lead->activities()->count());

        $done = $this->exec($shop, 'log_followup', ['name' => 'marina', 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $fresh = $lead->fresh();
        $this->assertSame('sent', $fresh->status); // unchanged
        $this->assertNotNull($fresh->last_contacted_at);
        $this->assertSame(1, $fresh->activities()->where('type', LeadActivity::TYPE_CONTACTED)->count());
    }

    public function test_draft_outreach_returns_message(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'new']);

        $writer = Mockery::mock(\App\Services\Leads\OutreachWriter::class);
        $writer->shouldReceive('personalizeForLead')->once()->andReturn('Hi Marina Gym, quick idea for you...');
        $this->app->instance(\App\Services\Leads\OutreachWriter::class, $writer);

        $out = $this->exec($shop, 'draft_outreach', ['name' => 'marina', 'kind' => 'opening']);
        $this->assertSame('Marina Gym', $out['name']);
        $this->assertSame('opening', $out['kind']);
        $this->assertStringContainsString('Marina Gym', $out['message']);
    }

    public function test_draft_outreach_handles_writer_failure(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'new']);

        $writer = Mockery::mock(\App\Services\Leads\OutreachWriter::class);
        $writer->shouldReceive('personalizeForLead')->andThrow(new \RuntimeException('AI down'));
        $this->app->instance(\App\Services\Leads\OutreachWriter::class, $writer);

        $out = $this->exec($shop, 'draft_outreach', ['name' => 'marina']);
        $this->assertSame('draft_failed', $out['error']);
    }

    public function test_draft_outreach_not_found(): void
    {
        $out = $this->exec($this->leadsShop(), 'draft_outreach', ['name' => 'nobody']);
        $this->assertSame('not_found', $out['error']);
    }

    public function test_leads_shop_exposes_new_hunt_tools(): void
    {
        $names = array_column(app(AssistantToolRegistry::class)->defs($this->leadsShop()), 'name');
        foreach (['hunt_income', 'log_followup', 'draft_outreach'] as $t) {
            $this->assertContains($t, $names);
        }
    }

    public function test_bookings_only_shop_hides_new_hunt_tools(): void
    {
        $shop = Shop::create(['name' => 'B2', 'shop_code' => '7102', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings']]);
        $names = array_column(app(AssistantToolRegistry::class)->defs($shop), 'name');
        foreach (['hunt_income', 'log_followup', 'draft_outreach'] as $t) {
            $this->assertNotContains($t, $names);
        }
    }
}
