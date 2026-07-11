<?php
namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Services\Assistant\AssistantToolRegistry;
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

    public function test_search_businesses_returns_cached_results(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('search')->never(); // cache-only: never a live/billable search
            $s->shouldReceive('cached')->once()->andReturn([
                ['name' => 'Gym One', 'external_ref' => 'g1'],
                ['name' => 'Gym Two', 'external_ref' => 'g2'],
            ]);
        });

        $out = $this->exec($shop, 'search_businesses', ['category' => 'gyms']);
        $this->assertSame(2, $out['count']);
        $this->assertTrue($out['from_cache']);
        $this->assertSame(['Gym One', 'Gym Two'], $out['sample']);
        $this->assertArrayNotHasKey('credits_left', $out); // nothing charged
    }

    public function test_search_businesses_no_cache_directs_to_hunt_screen(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('search')->never();
            $s->shouldReceive('cached')->andReturn(null);
        });

        $out = $this->exec($shop, 'search_businesses', ['category' => 'gyms']);
        $this->assertSame('no_cached_results', $out['error']);
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

    public function test_search_businesses_uses_interpreted_term_not_raw_input(): void
    {
        $shop = $this->leadsShop();

        $interp = Mockery::mock(SearchInterpreter::class);
        $interp->shouldReceive('interpret')->andReturn(['keyword' => 'hotels', 'area' => 'Dubai']);
        $this->app->instance(SearchInterpreter::class, $interp);

        $search = Mockery::mock(LeadSearchService::class);
        $search->shouldReceive('search')->never();      // never a live/billable search
        $search->shouldReceive('cached')->with('hotels', 'Dubai')->once()->andReturn([['name' => 'Grand Hotel', 'external_ref' => 'h1']]);
        $this->app->instance(LeadSearchService::class, $search);

        $out = $this->exec($shop, 'search_businesses', ['category' => 'find me customers']);
        $this->assertSame('hotels', $out['searched_for']);
        $this->assertSame(1, $out['count']);
    }
}
