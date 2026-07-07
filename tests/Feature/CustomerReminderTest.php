<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.default_token' => 'system-token',
        ]);
    }

    private function shopWithWa(array $attrs = []): Shop
    {
        $shop = Shop::factory()->create(array_merge(['name' => 'Glow Salon'], $attrs));
        WaAccount::create([
            'shop_id'         => $shop->id,
            'phone_number'    => '+971500000001',
            'phone_number_id' => 'pn_' . $shop->id,
            'waba_id'         => 'waba_' . $shop->id,
        ]);
        return $shop;
    }

    /** A booking whose appointment starts $hoursAhead from now. */
    private function bookingAt(Shop $shop, float $hoursAhead, array $attrs = []): Booking
    {
        $when = now()->addMinutes((int) round($hoursAhead * 60));
        return Booking::create(array_merge([
            'shop_id'           => $shop->id,
            'date'              => $when->toDateString(),
            'start_time'        => $when->format('H:i:s'),
            'end_time'          => $when->copy()->addMinutes(30)->format('H:i:s'),
            'status'            => 'booked',
            'customer_name'     => 'Aisha',
            'customer_whatsapp' => '971555000111',
            'charges'           => 0,
            'services'          => [],
        ], $attrs));
    }

    private function fakeGraph(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.R1']]])]);
    }

    public function test_sends_reminder_for_booking_in_24h_window_and_sets_flag(): void
    {
        $this->fakeGraph();
        $shop = $this->shopWithWa();
        $booking = $this->bookingAt($shop, 20); // inside 24h

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertNotNull($booking->fresh()->reminder_customer_sent_at);
        Http::assertSent(function ($req) {
            $body = $req->data();
            return str_contains($req->url(), 'graph.facebook.com')
                && ($body['type'] ?? null) === 'text'
                && str_contains($body['text']['body'] ?? '', 'Aisha')
                && str_contains($body['text']['body'] ?? '', 'Glow Salon');
        });
    }

    public function test_does_not_send_outside_window_or_when_already_reminded_or_cancelled(): void
    {
        $this->fakeGraph();
        $shop = $this->shopWithWa();
        $this->bookingAt($shop, 40);                                    // too far out
        $this->bookingAt($shop, 10, ['status' => 'cancelled']);        // cancelled
        $this->bookingAt($shop, 10, ['reminder_customer_sent_at' => now()]); // already reminded

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_send_when_disabled_or_no_wa_account(): void
    {
        $this->fakeGraph();
        // reminders disabled
        $disabled = $this->shopWithWa(['booking_reminders_enabled' => false]);
        $this->bookingAt($disabled, 10);
        // no WA account
        $noWa = Shop::factory()->create();
        $this->bookingAt($noWa, 10);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_tenant_isolation_uses_each_shops_own_account_and_name(): void
    {
        $this->fakeGraph();
        $a = $this->shopWithWa(['name' => 'Marina Spa']);
        $b = $this->shopWithWa(['name' => 'Downtown Clinic']);
        $this->bookingAt($a, 10);
        $this->bookingAt($b, 10);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        // Each shop's message carries its own name; never the other's.
        Http::assertSent(fn ($r) => str_contains($r->data()['text']['body'] ?? '', 'Marina Spa')
            && !str_contains($r->data()['text']['body'] ?? '', 'Downtown Clinic'));
        Http::assertSent(fn ($r) => str_contains($r->data()['text']['body'] ?? '', 'Downtown Clinic')
            && !str_contains($r->data()['text']['body'] ?? '', 'Marina Spa'));
    }

    public function test_send_failure_does_not_mark_booking_reminded(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 400)]);
        $shop = $this->shopWithWa();
        $booking = $this->bookingAt($shop, 10);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertNull($booking->fresh()->reminder_customer_sent_at);
    }
}
