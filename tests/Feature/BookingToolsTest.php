<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaReply;
use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\Staff;
use App\Models\WaContact;
use App\Services\Wa\BookingTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The chat assistant's booking tools: real availability, real bookings, and
 * every booking registers the customer (ShopCustomer) so returning customers
 * are recognised by phone.
 */
class BookingToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.webpush.public_key' => null]);
        Http::fake(['push.eloquentservice.com/*' => Http::response(['ok' => true])]);
    }

    private function salon(): array
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9]);
        $shop->catalogs()->create(['title' => 'Haircut', 'price' => 50]);
        Staff::create(['shop_id' => $shop->id, 'name' => 'Maya', 'is_active' => true]);
        $contact = WaContact::create(['channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-book']);

        return [$shop, $contact];
    }

    /** A guaranteed-open future date: next Monday (factory hours are Mon–Sat). */
    private function nextMonday(): string
    {
        return now('Asia/Dubai')->next('Monday')->toDateString();
    }

    private function tool(Shop $shop, WaContact $contact, string $tool, array $input): array
    {
        return json_decode((new BookingTools())->execute($shop, $contact, $tool, $input), true);
    }

    public function test_availability_lists_open_slots_and_excludes_booked_ones(): void
    {
        [$shop, $contact] = $this->salon();
        $date = $this->nextMonday();
        Booking::create(['shop_id' => $shop->id, 'status' => 'booked', 'date' => $date, 'start_time' => '10:00', 'end_time' => '10:30']);

        $res = $this->tool($shop, $contact, 'check_availability', ['date' => $date]);

        $this->assertSame('Monday', $res['day']);
        $this->assertContains('09:00', $res['free_slots']);
        $this->assertNotContains('10:00', $res['free_slots']);
    }

    public function test_availability_reports_closed_days_and_rejects_past_dates(): void
    {
        [$shop, $contact] = $this->salon();
        $sunday = now('Asia/Dubai')->next('Sunday')->toDateString();

        $this->assertTrue($this->tool($shop, $contact, 'check_availability', ['date' => $sunday])['closed']);
        $this->assertArrayHasKey('error', $this->tool($shop, $contact, 'check_availability', ['date' => '2020-01-01']));
    }

    public function test_create_booking_registers_the_customer_and_books_the_slot(): void
    {
        [$shop, $contact] = $this->salon();
        $date = $this->nextMonday();

        $res = $this->tool($shop, $contact, 'create_booking', [
            'date' => $date, 'time' => '10:00',
            'customer_name' => 'Aisha Khan', 'customer_phone' => '+971 55 000 1111',
            'service_title' => 'Haircut',
        ]);

        $this->assertTrue($res['booked']);
        $this->assertFalse($res['returning_customer']);
        $this->assertSame('Haircut', $res['service']);
        $this->assertEquals(50, $res['price_aed']);
        $this->assertMatchesRegularExpression('/^BK\d{5}$/', $res['reference']);

        $booking = Booking::where('booking_reference', $res['reference'])->firstOrFail();
        $customer = ShopCustomer::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame('Aisha Khan', $customer->name);
        $this->assertSame('971550001111', $customer->whatsapp_normalized);
        $this->assertSame($customer->id, $booking->shop_customer_id);
        $this->assertSame('Booked', $booking->status);
        $this->assertSame(50.0, (float) $booking->charges);
        // The chat thread picked up the customer's identity for the owner inbox.
        $this->assertSame('Aisha Khan', $contact->fresh()->name);
        $this->assertSame('971550001111', $contact->fresh()->wa_number);
    }

    public function test_second_booking_by_same_phone_reuses_the_registered_customer(): void
    {
        [$shop, $contact] = $this->salon();
        $date = $this->nextMonday();

        $this->tool($shop, $contact, 'create_booking', [
            'date' => $date, 'time' => '10:00', 'customer_name' => 'Aisha Khan', 'customer_phone' => '+971550001111',
        ]);
        $res = $this->tool($shop, $contact, 'create_booking', [
            'date' => $date, 'time' => '11:00', 'customer_name' => 'Aisha', 'customer_phone' => '0550001111',
        ]);

        $this->assertTrue($res['booked']);
        $this->assertTrue($res['returning_customer']);
        $this->assertSame(1, ShopCustomer::where('shop_id', $shop->id)->count());
        $this->assertSame(2, Booking::where('shop_id', $shop->id)->count());
    }

    public function test_taken_slot_is_rejected_with_alternatives(): void
    {
        [$shop, $contact] = $this->salon();
        $date = $this->nextMonday();
        Booking::create(['shop_id' => $shop->id, 'status' => 'booked', 'date' => $date, 'start_time' => '10:00', 'end_time' => '10:30']);

        $res = $this->tool($shop, $contact, 'create_booking', [
            'date' => $date, 'time' => '10:00', 'customer_name' => 'Aisha', 'customer_phone' => '+971550001111',
        ]);

        $this->assertArrayHasKey('error', $res);
        $this->assertNotContains('10:00', $res['free_slots']);
        $this->assertSame(0, ShopCustomer::count());
    }

    public function test_missing_name_or_phone_is_rejected(): void
    {
        [$shop, $contact] = $this->salon();

        $res = $this->tool($shop, $contact, 'create_booking', [
            'date' => $this->nextMonday(), 'time' => '10:00', 'customer_name' => 'Aisha', 'customer_phone' => 'no number',
        ]);

        $this->assertArrayHasKey('error', $res);
        $this->assertSame(0, Booking::count());
    }

    private function bookedCustomer(Shop $shop, WaContact $contact, string $time = '10:00'): array
    {
        $res = $this->tool($shop, $contact, 'create_booking', [
            'date' => $this->nextMonday(), 'time' => $time,
            'customer_name' => 'Aisha Khan', 'customer_phone' => '+971550001111',
            'service_title' => 'Haircut',
        ]);

        return [ShopCustomer::where('shop_id', $shop->id)->firstOrFail(), $res['reference']];
    }

    public function test_my_bookings_lists_upcoming_for_recognised_customer(): void
    {
        [$shop, $contact] = $this->salon();
        [, $ref] = $this->bookedCustomer($shop, $contact);

        // Recognised via the device that booked — no phone needed.
        $res = $this->tool($shop, $contact, 'my_bookings', []);

        $this->assertSame('Aisha Khan', $res['customer']);
        $this->assertCount(1, $res['upcoming_bookings']);
        $this->assertStringContainsString($ref, $res['upcoming_bookings'][0]);
        $this->assertStringContainsString('Haircut', $res['upcoming_bookings'][0]);
    }

    public function test_cancel_requires_ownership_and_cancels(): void
    {
        [$shop, $contact] = $this->salon();
        [, $ref] = $this->bookedCustomer($shop, $contact);

        // A stranger thread (different device, no phone) cannot touch it.
        $stranger = WaContact::create(['channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-stranger']);
        $denied = $this->tool($shop, $stranger, 'cancel_booking', ['reference' => $ref]);
        $this->assertArrayHasKey('error', $denied);

        // The owner thread can.
        $res = $this->tool($shop, $contact, 'cancel_booking', ['reference' => $ref]);
        $this->assertTrue($res['cancelled']);

        $booking = Booking::where('booking_reference', $ref)->firstOrFail();
        $this->assertSame('Cancelled', $booking->status);
        $this->assertNull($booking->staff_id);

        // And it cannot be cancelled twice.
        $again = $this->tool($shop, $contact, 'cancel_booking', ['reference' => $ref]);
        $this->assertArrayHasKey('error', $again);
    }

    public function test_cancel_frees_the_slot_and_promotes_a_queued_booking(): void
    {
        [$shop, $contact] = $this->salon();
        [, $ref] = $this->bookedCustomer($shop, $contact); // takes Maya at 10:00
        $queued = Booking::create([
            'shop_id' => $shop->id, 'status' => 'queued', 'date' => $this->nextMonday(),
            'start_time' => '10:00', 'end_time' => '10:30', 'customer_name' => 'Walk In',
        ]);

        $this->tool($shop, $contact, 'cancel_booking', ['reference' => $ref]);

        $this->assertSame('Booked', $queued->fresh()->status);
        $this->assertNotNull($queued->fresh()->staff_id);
    }

    public function test_reschedule_moves_to_a_free_slot_and_rejects_taken_ones(): void
    {
        [$shop, $contact] = $this->salon();
        [, $ref] = $this->bookedCustomer($shop, $contact, '10:00');
        Booking::create(['shop_id' => $shop->id, 'status' => 'booked', 'date' => $this->nextMonday(), 'start_time' => '11:00', 'end_time' => '11:30']);

        $taken = $this->tool($shop, $contact, 'reschedule_booking', [
            'reference' => $ref, 'new_date' => $this->nextMonday(), 'new_time' => '11:00',
        ]);
        $this->assertArrayHasKey('error', $taken);

        $res = $this->tool($shop, $contact, 'reschedule_booking', [
            'reference' => $ref, 'new_date' => $this->nextMonday(), 'new_time' => '12:00',
        ]);
        $this->assertTrue($res['rescheduled']);
        $this->assertSame('12:00', $res['time']);

        $booking = Booking::where('booking_reference', $ref)->firstOrFail();
        $this->assertSame('12:00', $booking->slot);
        $this->assertNull($booking->reminder_sent_at);
    }

    public function test_full_pipeline_books_via_tool_loop(): void
    {
        [$shop, $contact] = $this->salon();
        $date = $this->nextMonday();

        Http::fake([
            'push.eloquentservice.com/*' => Http::response(['ok' => true]),
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push(['content' => [
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'create_booking', 'input' => [
                        'date' => $date, 'time' => '10:00',
                        'customer_name' => 'Aisha Khan', 'customer_phone' => '+971550001111',
                        'service_title' => 'Haircut',
                    ]],
                ]])
                ->push(['content' => [['type' => 'text', 'text' => 'Booked! Your reference is BK00001 🎉']]]),
        ]);

        $inbound = $contact->recordMessage('in', 'Yes please book it, Aisha Khan, +971550001111');
        dispatch_sync(new ProcessWaReply($inbound->id));

        $this->assertSame(1, Booking::where('shop_id', $shop->id)->count());
        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString('BK00001', $out->body);
        // The model saw the booking tools and the strict booking-flow rules.
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'anthropic')) {
                return true;
            }
            $names = array_column($request['tools'] ?? [], 'name');
            return in_array('create_booking', $names, true)
                && str_contains($request['system'][0]['text'], 'Booking flow');
        });
    }
}
