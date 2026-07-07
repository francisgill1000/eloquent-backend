<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use App\Models\WaAccount;
use App\Services\Booking\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaitlistNotifyTest extends TestCase
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

    private function shop(array $attrs = [], bool $withWa = true): Shop
    {
        $shop = Shop::factory()->create(array_merge(['name' => 'Glow Salon'], $attrs));
        if ($withWa) {
            WaAccount::create([
                'shop_id' => $shop->id, 'phone_number' => '+971500000001',
                'phone_number_id' => 'pn_' . $shop->id, 'waba_id' => 'waba_' . $shop->id,
            ]);
        }
        return $shop;
    }

    /** One staff, a booked booking occupying them, and a queued booking at the same slot. */
    private function setupPromotion(Shop $shop, array $queuedAttrs = []): array
    {
        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $booked = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked',
            'charges' => 0, 'services' => [], 'customer_name' => 'First',
        ]);
        $queued = Booking::create(array_merge([
            'shop_id' => $shop->id, 'staff_id' => null, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'queued',
            'charges' => 0, 'services' => [], 'customer_name' => 'Aisha',
            'customer_whatsapp' => '971555000111',
        ], $queuedAttrs));
        return [$booked, $queued];
    }

    public function test_promotion_notifies_the_waitlisted_customer(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.P']]])]);
        $shop = $this->shop();
        [$booked, $queued] = $this->setupPromotion($shop);

        // Cancel the booked one → frees the staff → sweep promotes the queued one.
        app(BookingStatusService::class)->apply($booked, 'cancelled');

        $this->assertSame('booked', strtolower($queued->fresh()->getRawOriginal('status')));
        Http::assertSent(function ($req) use ($queued) {
            $body = $req->data()['text']['body'] ?? '';
            return str_contains($body, 'Glow Salon')
                && str_contains($body, $queued->booking_reference)
                && str_contains($body, 'Aisha');
        });
    }

    public function test_no_notify_when_disabled_or_no_account_or_no_whatsapp(): void
    {
        // disabled
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);
        $disabled = $this->shop(['waitlist_notify_enabled' => false]);
        [$b1] = $this->setupPromotion($disabled);
        app(BookingStatusService::class)->apply($b1, 'cancelled');

        // no WA account
        $noWa = $this->shop([], withWa: false);
        [$b2] = $this->setupPromotion($noWa);
        app(BookingStatusService::class)->apply($b2, 'cancelled');

        // no customer whatsapp on the queued booking
        $shop = $this->shop();
        [$b3] = $this->setupPromotion($shop, ['customer_whatsapp' => null]);
        app(BookingStatusService::class)->apply($b3, 'cancelled');

        // No WhatsApp send in any of the three cases. (The promotion path also
        // emits an internal push notification via Notify::push, which the fake
        // records — so assert specifically that no Graph/WhatsApp call was made.)
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com'));
    }

    public function test_whatsapp_failure_still_promotes_the_booking(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 400)]);
        $shop = $this->shop();
        [$booked, $queued] = $this->setupPromotion($shop);

        app(BookingStatusService::class)->apply($booked, 'cancelled');

        // Promotion stands despite the messaging failure.
        $this->assertSame('booked', strtolower($queued->fresh()->getRawOriginal('status')));
        $this->assertNotNull($queued->fresh()->staff_id);
    }
}
