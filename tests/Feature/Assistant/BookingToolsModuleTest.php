<?php
namespace Tests\Feature\Assistant;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Assistant\Modules\BookingTools;
use App\Services\Assistant\Support\ToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingToolsModuleTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'S', 'shop_code' => '8200', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    private function booking(Shop $shop, string $ref = 'BK00001'): Booking
    {
        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $b = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked', 'charges' => 40,
            'discount_amount' => 0, 'services' => [], 'customer_name' => 'C',
        ]);
        $b->update(['booking_reference' => $ref]); // pin a known reference
        return $b->fresh();
    }

    private function toolCall(Shop $shop, string $tool, array $input, bool $confirmed): ToolCall
    {
        return new ToolCall($shop, null, $tool, $input, $confirmed); // null user = owner-equivalent
    }

    public function test_cancel_unconfirmed_previews_and_does_not_write(): void
    {
        $shop = $this->shop();
        $this->booking($shop);
        $out = app(BookingTools::class)->run($this->toolCall($shop, 'cancel_booking', ['reference' => 'BK00001'], false));

        $this->assertTrue($out['preview']);
        $this->assertSame('booked', strtolower(Booking::where('booking_reference', 'BK00001')->first()->getRawOriginal('status')));
    }

    public function test_cancel_confirmed_frees_staff_via_status_service(): void
    {
        $shop = $this->shop();
        $this->booking($shop);
        $out = app(BookingTools::class)->run($this->toolCall($shop, 'cancel_booking', ['reference' => 'BK00001'], true));

        $this->assertTrue($out['done']);
        $fresh = Booking::where('booking_reference', 'BK00001')->first();
        $this->assertSame('cancelled', strtolower($fresh->getRawOriginal('status')));
        $this->assertNull($fresh->staff_id); // side-effect via BookingStatusService
    }

    public function test_unknown_reference_returns_not_found(): void
    {
        $shop = $this->shop();
        $out = app(BookingTools::class)->run($this->toolCall($shop, 'cancel_booking', ['reference' => 'NOPE'], true));
        $this->assertSame('not_found', $out['error']);
    }

    public function test_booking_from_another_shop_is_not_found(): void
    {
        $shop = $this->shop();
        $other = Shop::create(['name' => 'O', 'shop_code' => '8299', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $this->booking($other, 'BK09999');
        $out = app(BookingTools::class)->run($this->toolCall($shop, 'cancel_booking', ['reference' => 'BK09999'], true));
        $this->assertSame('not_found', $out['error']); // scoped to acting shop
    }
}
