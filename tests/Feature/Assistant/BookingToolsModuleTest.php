<?php
namespace Tests\Feature\Assistant;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Assistant\Modules\BookingTools;
use App\Services\Assistant\Support\AssistantActions;
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

    public function test_open_booking_records_navigation_and_returns_opening(): void
    {
        $shop = $this->shop();
        $b = $this->booking($shop, 'BK00042');
        $actions = app(AssistantActions::class);

        $out = app(BookingTools::class)->run($this->toolCall($shop, 'open_booking', ['reference' => 'BK00042'], false));

        $this->assertTrue($out['opening']);
        $this->assertSame('BK00042', $out['reference']);
        $this->assertSame(['type' => 'navigate', 'route' => "/booking/{$b->id}"], $actions->pending());
    }

    public function test_open_unknown_booking_is_not_found_and_records_nothing(): void
    {
        $shop = $this->shop();
        $actions = app(AssistantActions::class);

        $out = app(BookingTools::class)->run($this->toolCall($shop, 'open_booking', ['reference' => 'NOPE'], false));

        $this->assertSame('not_found', $out['error']);
        $this->assertNull($actions->pending());
    }

    public function test_open_booking_from_another_shop_is_not_found(): void
    {
        $shop = $this->shop();
        $other = Shop::create(['name' => 'O', 'shop_code' => '8298', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $this->booking($other, 'BK08888');

        $out = app(BookingTools::class)->run($this->toolCall($shop, 'open_booking', ['reference' => 'BK08888'], false));
        $this->assertSame('not_found', $out['error']);
    }

    /** A shop that can actually take a booking: open today, one free staff member. */
    private function bookableShop(string $code = '8210'): Shop
    {
        $shop = Shop::create(['name' => 'S', 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        \DB::table('shop_working_hours')->updateOrInsert(
            ['shop_id' => $shop->id, 'day_of_week' => (int) now()->dayOfWeek],
            ['start_time' => '00:00:00', 'end_time' => '23:59:00', 'slot_duration' => 30, 'created_at' => now(), 'updated_at' => now()],
        );
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        return $shop;
    }

    private function service(Shop $shop, string $title, float $price): int
    {
        return \DB::table('catalogs')->insertGetId([
            'shop_id' => $shop->id, 'title' => $title, 'description' => '', 'price' => $price,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bookingInput(array $extra = []): array
    {
        return array_merge([
            'customer_name' => 'Sara', 'customer_whatsapp' => '971500000000',
            'date' => now()->toDateString(), 'start_time' => '10:00',
        ], $extra);
    }

    // --- create_booking: unresolvable services must fail loudly, not silently ---
    // BK00004 on prod was created with services=[] and charges=0 because the
    // requested title matched no catalog row and nothing checked for that.

    public function test_create_booking_with_unknown_service_is_not_found_and_writes_nothing(): void
    {
        $shop = $this->bookableShop();
        $this->service($shop, 'General', 100);

        $out = app(BookingTools::class)->run($this->toolCall(
            $shop, 'create_booking', $this->bookingInput(['services' => ['general checkup']]), true,
        ));

        $this->assertSame('not_found', $out['error']);
        $this->assertSame('service', $out['what']);
        $this->assertSame(['general checkup'], $out['unmatched']);
        $this->assertSame(['General'], $out['available']); // model can offer the real list
        $this->assertSame(0, Booking::where('shop_id', $shop->id)->count());
    }

    public function test_create_booking_with_unknown_service_is_not_found_before_the_preview(): void
    {
        $shop = $this->bookableShop('8211');
        $this->service($shop, 'General', 100);

        $out = app(BookingTools::class)->run($this->toolCall(
            $shop, 'create_booking', $this->bookingInput(['services' => ['general checkup']]), false,
        ));

        $this->assertSame('not_found', $out['error']);
        $this->assertArrayNotHasKey('preview', $out); // never previews a booking it can't price
    }

    public function test_create_booking_matches_service_titles_case_insensitively(): void
    {
        $shop = $this->bookableShop('8212');
        $this->service($shop, 'General Checkup', 100);

        $out = app(BookingTools::class)->run($this->toolCall(
            $shop, 'create_booking', $this->bookingInput(['services' => ['general checkup']]), true,
        ));

        $this->assertTrue($out['done']);
        $booking = Booking::where('shop_id', $shop->id)->first();
        // Compare numerically: sqlite and pgsql format decimals differently.
        $this->assertSame(['General Checkup'], array_column($booking->services, 'title'));
        $this->assertSame(100.0, (float) $booking->services[0]['price']);
        $this->assertSame(100.0, (float) $booking->charges);
    }

    public function test_create_booking_with_no_services_still_works(): void
    {
        // An owner may deliberately book a bare slot — only *unmatched* titles fail.
        $shop = $this->bookableShop('8213');

        $out = app(BookingTools::class)->run($this->toolCall($shop, 'create_booking', $this->bookingInput(), true));

        $this->assertTrue($out['done']);
        $this->assertSame([], Booking::where('shop_id', $shop->id)->first()->services);
    }

    // --- update_booking_services: repair a booking like BK00004 from chat ---

    public function test_update_booking_services_unconfirmed_previews_and_does_not_write(): void
    {
        $shop = $this->bookableShop('8214');
        $this->service($shop, 'General Checkup', 100);
        $this->booking($shop, 'BK00100');

        $out = app(BookingTools::class)->run($this->toolCall(
            $shop, 'update_booking_services', ['reference' => 'BK00100', 'services' => ['General Checkup']], false,
        ));

        $this->assertTrue($out['preview']);
        $this->assertFalse($out['saved']);
        $this->assertSame([], Booking::where('booking_reference', 'BK00100')->first()->services);
    }

    public function test_update_booking_services_confirmed_sets_services_and_recharges(): void
    {
        $shop = $this->bookableShop('8215');
        $this->service($shop, 'General Checkup', 100);
        $this->service($shop, 'X-Ray', 250);
        $this->booking($shop, 'BK00101'); // starts with services=[] and charges=40

        $out = app(BookingTools::class)->run($this->toolCall(
            $shop, 'update_booking_services', ['reference' => 'BK00101', 'services' => ['general checkup', 'x-ray']], true,
        ));

        $this->assertTrue($out['done']);
        $fresh = Booking::where('booking_reference', 'BK00101')->first();
        $this->assertSame(['General Checkup', 'X-Ray'], array_column($fresh->services, 'title'));
        $this->assertSame(350.0, (float) $fresh->charges);
    }

    public function test_update_booking_services_rejects_unknown_service(): void
    {
        $shop = $this->bookableShop('8216');
        $this->service($shop, 'General Checkup', 100);
        $this->booking($shop, 'BK00102');

        $out = app(BookingTools::class)->run($this->toolCall(
            $shop, 'update_booking_services', ['reference' => 'BK00102', 'services' => ['massage']], true,
        ));

        $this->assertSame('not_found', $out['error']);
        $this->assertSame(['massage'], $out['unmatched']);
        $this->assertSame(40.0, (float) Booking::where('booking_reference', 'BK00102')->first()->charges);
    }

    public function test_update_booking_services_for_another_shop_is_not_found(): void
    {
        $shop = $this->bookableShop('8217');
        $other = Shop::create(['name' => 'O', 'shop_code' => '8297', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $this->booking($other, 'BK07777');

        $out = app(BookingTools::class)->run($this->toolCall(
            $shop, 'update_booking_services', ['reference' => 'BK07777', 'services' => []], true,
        ));

        $this->assertSame('not_found', $out['error']);
    }
}
