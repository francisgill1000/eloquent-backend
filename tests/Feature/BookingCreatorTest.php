<?php
namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Staff;
use App\Models\ShopCustomer;
use App\Services\Booking\BookingCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCreatorTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithHours(): Shop
    {
        $shop = Shop::create(['name' => 'S', 'shop_code' => '8100', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        // The Shop `created` hook already seeds default hours for Mon-Sat, so
        // reconcile today's row instead of inserting a duplicate (which violates
        // the shop_id+day_of_week unique index on any weekday).
        \DB::table('shop_working_hours')->updateOrInsert(
            ['shop_id' => $shop->id, 'day_of_week' => (int) now()->dayOfWeek],
            [
                'start_time' => '09:00:00', 'end_time' => '18:00:00', 'slot_duration' => 30,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
        return $shop;
    }

    public function test_create_assigns_free_staff_and_registers_customer(): void
    {
        $shop = $this->shopWithHours();
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $booking = app(BookingCreator::class)->create($shop, [
            'customer_name' => 'Sara', 'customer_whatsapp' => '971500000000',
            'date' => now()->toDateString(), 'start_time' => '10:00',
            'services' => [['title' => 'Cut', 'price' => '50.00']], 'charges' => 50.0,
        ]);

        $this->assertSame('booked', strtolower($booking->getRawOriginal('status')));
        $this->assertNotNull($booking->staff_id);
        $this->assertSame('Sara', $booking->customer_name);
        $this->assertNotNull($booking->booking_reference);
        $this->assertNotNull(ShopCustomer::where('shop_id', $shop->id)->first());
    }

    public function test_create_without_free_staff_queues(): void
    {
        $shop = $this->shopWithHours(); // no staff created
        $booking = app(BookingCreator::class)->create($shop, [
            'customer_name' => 'Sara', 'customer_whatsapp' => '971500000001',
            'date' => now()->toDateString(), 'start_time' => '10:00',
            'services' => [], 'charges' => 0.0,
        ]);
        $this->assertSame('queued', strtolower($booking->getRawOriginal('status')));
        $this->assertNull($booking->staff_id);
    }

    public function test_create_without_contact_number_is_rejected(): void
    {
        $shop = $this->shopWithHours();
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(BookingCreator::class)->create($shop, [
            'customer_name' => 'Sara', 'customer_whatsapp' => null,
            'date' => now()->toDateString(), 'start_time' => '10:00',
            'services' => [], 'charges' => 0.0,
        ]);
    }
}
