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
        $shop = Shop::create(['name' => 'A', 'shop_code' => 'SC001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        DB::table('bookings')->insert([
            'shop_id' => $shop->id, 'date' => now()->toDateString(), 'start_time' => '10:00',
            'end_time' => '10:30', 'status' => 'booked', 'charges' => 10, 'discount_amount' => 0,
            'services' => '[]', 'booking_reference' => 'BK00001', 'customer_name' => 'X',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $out = json_decode($this->tools()->execute($shop, 'cancel_booking', ['reference' => 'BK00001']), true);

        $this->assertTrue($out['cancelled']);
        $this->assertSame('cancelled', DB::table('bookings')->where('booking_reference', 'BK00001')->value('status'));

        // Cross-shop isolation: shop1 cannot cancel a booking that belongs to shop2
        $shop2 = Shop::create(['name' => 'B', 'shop_code' => 'SC002', 'pin' => '2', 'status' => 'active', 'category_id' => 11]);
        DB::table('bookings')->insert([
            'shop_id' => $shop2->id, 'date' => now()->toDateString(), 'start_time' => '11:00',
            'end_time' => '11:30', 'status' => 'booked', 'charges' => 20, 'discount_amount' => 0,
            'services' => '[]', 'booking_reference' => 'BK00002', 'customer_name' => 'Y',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Call cancel as shop1 but using shop2's booking reference
        $out2 = json_decode($this->tools()->execute($shop, 'cancel_booking', ['reference' => 'BK00002']), true);

        // (a) Should return an error — booking not found in shop1
        $this->assertArrayHasKey('error', $out2);

        // (b) Shop2's booking must remain untouched
        $this->assertSame('booked', DB::table('bookings')->where('booking_reference', 'BK00002')->value('status'));
    }

    public function test_cancel_booking_unknown_reference_returns_error(): void
    {
        $shop = Shop::create(['name' => 'A', 'shop_code' => 'SC003', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $out = json_decode($this->tools()->execute($shop, 'cancel_booking', ['reference' => 'NOPE']), true);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_update_service_price_changes_catalog(): void
    {
        $shop = Shop::create(['name' => 'A', 'shop_code' => 'SC004', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $id = DB::table('catalogs')->insertGetId([
            'shop_id' => $shop->id, 'title' => 'Wash & Fold', 'price' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $out = json_decode($this->tools()->execute($shop, 'update_service_price', ['catalog_id' => $id, 'price' => 15]), true);

        $this->assertTrue($out['updated']);
        $this->assertEquals(15, DB::table('catalogs')->where('id', $id)->value('price'));
    }

    public function test_update_booking_status_changes_status(): void
    {
        $shop = Shop::create(['name' => 'A', 'shop_code' => 'SC005', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        DB::table('bookings')->insert([
            'shop_id' => $shop->id, 'date' => now()->toDateString(), 'start_time' => '09:00',
            'end_time' => '09:30', 'status' => 'booked', 'charges' => 50, 'discount_amount' => 0,
            'services' => '[]', 'booking_reference' => 'BK00010', 'customer_name' => 'Z',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $out = json_decode(
            $this->tools()->execute($shop, 'update_booking_status', ['reference' => 'BK00010', 'status' => 'completed']),
            true
        );

        $this->assertTrue($out['updated']);
        $this->assertSame('completed', DB::table('bookings')->where('booking_reference', 'BK00010')->value('status'));
    }

    public function test_update_hours_inserts_then_updates_preserving_created_at(): void
    {
        $shop = Shop::create(['name' => 'A', 'shop_code' => 'SC006', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);

        // First call — inserts a new row
        $out1 = json_decode(
            $this->tools()->execute($shop, 'update_hours', ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']),
            true
        );
        $this->assertTrue($out1['updated']);

        $row1 = DB::table('shop_working_hours')
            ->where('shop_id', $shop->id)
            ->where('day_of_week', 1)
            ->first();

        $this->assertNotNull($row1);
        $this->assertEquals(30, $row1->slot_duration);
        $originalCreatedAt = $row1->created_at;

        // Second call — updates the existing row with different times
        $out2 = json_decode(
            $this->tools()->execute($shop, 'update_hours', ['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '18:00']),
            true
        );
        $this->assertTrue($out2['updated']);

        $row2 = DB::table('shop_working_hours')
            ->where('shop_id', $shop->id)
            ->where('day_of_week', 1)
            ->first();

        // Times updated
        $this->assertSame('10:00:00', $row2->start_time);
        $this->assertSame('18:00:00', $row2->end_time);

        // slot_duration preserved
        $this->assertEquals(30, $row2->slot_duration);

        // created_at unchanged
        $this->assertSame($originalCreatedAt, $row2->created_at);
    }
}
