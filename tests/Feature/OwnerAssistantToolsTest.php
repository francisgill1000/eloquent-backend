<?php
namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Assistant\OwnerAssistantTools;
use App\Services\Reports\ReportsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerAssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    private function tools(): OwnerAssistantTools
    {
        return app(OwnerAssistantTools::class);
    }

    private function seedShopWithBooking(): Shop
    {
        $shop = Shop::create([
            'name' => 'Test Laundry', 'shop_code' => '9001', 'pin' => '0000',
            'status' => 'active', 'category_id' => 11,
        ]);
        \DB::table('bookings')->insert([
            'shop_id' => $shop->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'completed',
            'charges' => 50, 'discount_amount' => 0,
            'services' => json_encode([['id' => 1, 'title' => 'Wash & Fold', 'price' => '50.00']]),
            'booking_reference' => 'BK90001', 'customer_name' => 'Test Cust',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $shop;
    }

    public function test_get_revenue_returns_this_shop_total(): void
    {
        $shop = $this->seedShopWithBooking();
        $out = json_decode($this->tools()->execute($shop, 'get_revenue', ['period' => 'this_month']), true);
        $this->assertSame(50, (int) $out['kpis']['gross_revenue']);
        $this->assertSame(1, (int) $out['kpis']['total_bookings']);
    }

    public function test_get_bookings_filters_by_status_and_scopes_to_shop(): void
    {
        $shop = $this->seedShopWithBooking();
        $other = Shop::create(['name' => 'Other', 'shop_code' => '9002', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        \DB::table('bookings')->insert([
            'shop_id' => $other->id, 'date' => now()->toDateString(),
            'start_time' => '11:00', 'end_time' => '11:30', 'status' => 'completed',
            'charges' => 999, 'discount_amount' => 0, 'services' => '[]',
            'booking_reference' => 'BK90099', 'customer_name' => 'Leak',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $out = json_decode($this->tools()->execute($shop, 'get_bookings', ['period' => 'this_month', 'status' => 'completed'], ), true);

        $this->assertSame(1, $out['count']);
        $refs = array_column($out['bookings'], 'reference');
        $this->assertContains('BK90001', $refs);
        $this->assertNotContains('BK90099', $refs); // never sees the other shop
    }

    public function test_module_run_delegates_to_execute(): void
    {
        $shop = $this->seedShopWithBooking();
        $call = new \App\Services\Assistant\Support\ToolCall($shop, null, 'get_revenue', ['period' => 'this_month'], false);

        $out = $this->tools()->run($call);

        $this->assertSame(50, (int) $out['kpis']['gross_revenue']);
        $this->assertTrue($this->tools()->handles('get_revenue'));
        $this->assertFalse($this->tools()->handles('nope'));
    }
}
