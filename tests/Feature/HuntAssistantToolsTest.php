<?php
namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Assistant\AssistantToolRegistry;
use App\Services\Credits\HuntCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
