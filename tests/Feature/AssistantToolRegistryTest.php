<?php
namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Assistant\AssistantToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'R', 'shop_code' => '7001', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_defs_include_the_legacy_read_tools(): void
    {
        $names = array_column(app(AssistantToolRegistry::class)->defs(), 'name');
        $this->assertContains('get_revenue', $names);
    }

    public function test_execute_routes_to_the_owning_module_and_returns_json(): void
    {
        $shop = $this->shop();
        $json = app(AssistantToolRegistry::class)->execute($shop, 'get_revenue', ['period' => 'this_month']);
        $out = json_decode($json, true);
        $this->assertArrayHasKey('kpis', $out);
    }

    public function test_unknown_tool_returns_error_json(): void
    {
        $json = app(AssistantToolRegistry::class)->execute($this->shop(), 'no_such_tool', []);
        $this->assertSame('unknown_tool', json_decode($json, true)['error']);
    }

    public function test_booking_tools_are_registered(): void
    {
        $names = array_column(app(AssistantToolRegistry::class)->defs(), 'name');
        $this->assertContains('cancel_booking', $names);
        $this->assertContains('create_booking', $names);
    }

    public function test_kill_switch_hides_mutating_booking_tools(): void
    {
        config(['assistant.mutations_enabled' => false]);
        $names = array_column(app(AssistantToolRegistry::class)->defs(), 'name');
        $this->assertNotContains('cancel_booking', $names);
        $this->assertContains('get_revenue', $names); // reads remain
    }

    public function test_leads_only_shop_excludes_booking_tools(): void
    {
        $shop = Shop::create(['name' => 'H', 'shop_code' => '7002', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
        $names = array_column(app(AssistantToolRegistry::class)->defs($shop), 'name');
        $this->assertNotContains('create_booking', $names);
        $this->assertNotContains('get_revenue', $names);
        $this->assertContains('get_business_profile', $names); // universal module stays
    }

    public function test_bookings_only_shop_keeps_booking_tools(): void
    {
        $shop = Shop::create(['name' => 'B', 'shop_code' => '7003', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings']]);
        $names = array_column(app(AssistantToolRegistry::class)->defs($shop), 'name');
        $this->assertContains('create_booking', $names);
        $this->assertContains('get_revenue', $names);
        $this->assertContains('get_business_profile', $names);
    }
}
