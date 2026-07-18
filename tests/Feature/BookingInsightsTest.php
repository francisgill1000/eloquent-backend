<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingInvoice;
use App\Models\BookingReview;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\Staff;
use App\Services\Booking\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function actingOwner(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = \App\Models\ShopUser::factory()->create(['shop_id' => $shop->id]);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    private function booking(Shop $shop, string $status, array $attrs = []): Booking
    {
        return Booking::create(array_merge([
            'shop_id' => $shop->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => $status,
            'charges' => 50, 'services' => [], 'customer_name' => 'C',
        ], $attrs));
    }

    public function test_no_show_frees_staff_sweeps_and_leaves_invoice_untouched(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $booked = $this->booking($shop, 'booked', ['staff_id' => $staff->id]);
        $invoice = BookingInvoice::create([
            'booking_id' => $booked->id, 'subtotal' => 50, 'total' => 50,
            'status' => 'issued', 'issued_at' => now(),
        ]);
        $queued = $this->booking($shop, 'queued', ['staff_id' => null, 'customer_name' => 'Q']);

        app(BookingStatusService::class)->apply($booked, 'no_show');

        $this->assertSame('no_show', strtolower($booked->fresh()->getRawOriginal('status')));
        $this->assertNull($booked->fresh()->staff_id);
        // Waitlist promoted into the freed slot.
        $this->assertSame('booked', strtolower($queued->fresh()->getRawOriginal('status')));
        // Invoice left as-is (owner decides; no auto-cancel).
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_update_endpoint_accepts_no_show(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);
        $b = $this->booking($shop, 'booked');
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/booking/{$b->id}", ['status' => 'no_show'])
            ->assertOk()
            ->assertJsonPath('data.status', 'No_Show');
    }

    public function test_insights_counts_and_rates(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);
        for ($i = 0; $i < 3; $i++) $this->booking($shop, 'completed');
        $this->booking($shop, 'cancelled');
        $this->booking($shop, 'no_show');

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/shop/reports/insights?from=' . now()->subDays(3)->toDateString()
                . '&to=' . now()->toDateString())
            ->assertOk();

        $this->assertSame(5, $res->json('bookings.scheduled'));
        $this->assertSame(3, $res->json('bookings.completed'));
        $this->assertSame(1, $res->json('bookings.no_show'));
        $this->assertEqualsWithDelta(60.0, $res->json('rates.completion'), 0.01);
        $this->assertEqualsWithDelta(20.0, $res->json('rates.no_show'), 0.01);
        $this->assertEqualsWithDelta(20.0, $res->json('rates.cancellation'), 0.01);
    }

    public function test_insights_new_vs_returning_across_range_boundary(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);
        $a = ShopCustomer::create(['shop_id' => $shop->id, 'name' => 'A', 'whatsapp' => '9711', 'whatsapp_normalized' => '9711']);
        $b = ShopCustomer::create(['shop_id' => $shop->id, 'name' => 'B', 'whatsapp' => '9712', 'whatsapp_normalized' => '9712']);

        // A booked before the range AND inside it → returning.
        $this->booking($shop, 'completed', ['shop_customer_id' => $a->id, 'date' => now()->subDays(30)->toDateString()]);
        $this->booking($shop, 'completed', ['shop_customer_id' => $a->id]);
        // B only inside the range → new.
        $this->booking($shop, 'completed', ['shop_customer_id' => $b->id]);

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/shop/reports/insights?from=' . now()->subDays(3)->toDateString()
                . '&to=' . now()->toDateString())
            ->assertOk();

        $this->assertSame(2, $res->json('customers.total'));
        $this->assertSame(1, $res->json('customers.returning'));
        $this->assertSame(1, $res->json('customers.new'));
        $this->assertEqualsWithDelta(50.0, $res->json('customers.repeat_rate'), 0.01);
    }

    public function test_insights_rating_summary_is_tenant_scoped(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);
        $other = Shop::factory()->create();
        $mk = function (Shop $s, int $rating) {
            $booking = $this->booking($s, 'completed', ['customer_whatsapp' => '971555000111']);
            return BookingReview::create([
                'shop_id' => $s->id, 'booking_id' => $booking->id,
                'rating' => $rating, 'rated_at' => now(),
            ]);
        };
        $mk($shop, 5);
        $mk($shop, 3);
        $mk($other, 1); // must not leak into $shop's summary

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/shop/reports/insights?from=' . now()->subDays(3)->toDateString()
                . '&to=' . now()->toDateString())
            ->assertOk();

        $this->assertSame(2, $res->json('reviews.count'));
        $this->assertEqualsWithDelta(4.0, $res->json('reviews.average'), 0.01);
    }
}
