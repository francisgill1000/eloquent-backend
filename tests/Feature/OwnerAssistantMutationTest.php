<?php
namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Assistant\OwnerAssistantTools;
use App\Services\Reports\ReportsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnerAssistantMutationTest extends TestCase
{
    use RefreshDatabase;

    private function tools(): OwnerAssistantTools
    {
        return new OwnerAssistantTools(app(ReportsAggregator::class));
    }

    public function test_cancel_booking_sets_status_and_is_shop_scoped(): void
    {
        $shop = Shop::create(['name' => 'A', 'shop_code' => '1', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        DB::table('bookings')->insert([
            'shop_id' => $shop->id, 'date' => now()->toDateString(), 'start_time' => '10:00',
            'end_time' => '10:30', 'status' => 'booked', 'charges' => 10, 'discount_amount' => 0,
            'services' => '[]', 'booking_reference' => 'BK00001', 'customer_name' => 'X',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $out = json_decode($this->tools()->execute($shop, 'cancel_booking', ['reference' => 'BK00001']), true);

        $this->assertTrue($out['cancelled']);
        $this->assertSame('cancelled', DB::table('bookings')->where('booking_reference', 'BK00001')->value('status'));
    }

    public function test_cancel_booking_unknown_reference_returns_error(): void
    {
        $shop = Shop::create(['name' => 'A', 'shop_code' => '1', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $out = json_decode($this->tools()->execute($shop, 'cancel_booking', ['reference' => 'NOPE']), true);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_update_service_price_changes_catalog(): void
    {
        $shop = Shop::create(['name' => 'A', 'shop_code' => '1', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $id = DB::table('catalogs')->insertGetId([
            'shop_id' => $shop->id, 'title' => 'Wash & Fold', 'price' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $out = json_decode($this->tools()->execute($shop, 'update_service_price', ['catalog_id' => $id, 'price' => 15]), true);

        $this->assertTrue($out['updated']);
        $this->assertEquals(15, DB::table('catalogs')->where('id', $id)->value('price'));
    }
}
