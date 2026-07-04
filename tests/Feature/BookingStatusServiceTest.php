<?php
namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingInvoice;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Booking\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'S', 'shop_code' => '8001', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_cancelling_a_booked_slot_frees_staff_and_cancels_invoice(): void
    {
        $shop = $this->shop();
        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $booking = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked', 'charges' => 40,
            'discount_amount' => 0, 'services' => [], 'customer_name' => 'C',
        ]);
        BookingInvoice::create(['booking_id' => $booking->id, 'subtotal' => 40, 'total' => 40, 'status' => 'issued', 'issued_at' => now()]);

        app(BookingStatusService::class)->apply($booking, 'cancelled');

        $this->assertSame('cancelled', strtolower($booking->fresh()->getRawOriginal('status')));
        $this->assertNull($booking->fresh()->staff_id);
        $this->assertSame('cancelled', $booking->fresh()->invoice->status);
    }

    public function test_completing_a_booked_slot_issues_an_invoice(): void
    {
        $shop = $this->shop();
        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $booking = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '11:00', 'end_time' => '11:30', 'status' => 'booked', 'charges' => 60,
            'discount_amount' => 0, 'services' => [], 'customer_name' => 'C',
        ]);

        app(BookingStatusService::class)->apply($booking, 'completed');

        $this->assertSame('completed', strtolower($booking->fresh()->getRawOriginal('status')));
        $this->assertNotNull($booking->fresh()->invoice);
        $this->assertSame('issued', $booking->fresh()->invoice->status);
    }
}
