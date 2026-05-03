<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingInvoice;
use App\Models\Shop;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_completing_a_booking_creates_an_invoice(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);
        $booking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'status' => 'booked', 'charges' => 75,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $this->putJson("/api/booking/{$booking->id}", ['status' => 'completed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('booking_invoices', [
            'booking_id' => $booking->id,
            'subtotal'   => '75.00',
            'total'      => '75.00',
            'status'     => 'issued',
        ]);
    }

    public function test_completing_twice_is_idempotent(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);
        $booking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'status' => 'booked', 'charges' => 75,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $this->putJson("/api/booking/{$booking->id}", ['status' => 'completed']);
        $this->putJson("/api/booking/{$booking->id}", ['status' => 'completed']);

        $this->assertEquals(1, BookingInvoice::where('booking_id', $booking->id)->count());
    }

    public function test_cancelling_a_booking_cancels_its_invoice(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);
        $booking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'status' => 'booked', 'charges' => 75,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $this->putJson("/api/booking/{$booking->id}", ['status' => 'completed']);
        $this->putJson("/api/booking/{$booking->id}", ['status' => 'cancelled']);

        $this->assertDatabaseHas('booking_invoices', [
            'booking_id' => $booking->id,
            'status'     => 'cancelled',
        ]);
    }

    public function test_get_invoice_json_returns_invoice_for_completed_booking(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);
        $booking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'status' => 'booked', 'charges' => 100,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $this->putJson("/api/booking/{$booking->id}", ['status' => 'completed']);

        $this->getJson("/api/booking/{$booking->id}/invoice")
            ->assertStatus(200)
            ->assertJsonPath('data.booking_id', $booking->id)
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.total', '100.00');
    }

    public function test_get_invoice_returns_404_when_no_invoice_exists(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);
        $booking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'status' => 'booked', 'charges' => 100,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $this->getJson("/api/booking/{$booking->id}/invoice")
            ->assertStatus(404);
    }

    public function test_mark_paid_flips_status_and_sets_paid_at(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);
        $booking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'status' => 'booked', 'charges' => 100,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);
        $this->putJson("/api/booking/{$booking->id}", ['status' => 'completed']);

        $invoiceId = BookingInvoice::where('booking_id', $booking->id)->first()->id;

        $this->postJson("/api/invoice/{$invoiceId}/mark-paid")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'paid');

        $this->assertNotNull(BookingInvoice::find($invoiceId)->paid_at);
    }

    public function test_mark_paid_returns_409_if_already_paid(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);
        $booking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'status' => 'booked', 'charges' => 100,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);
        $this->putJson("/api/booking/{$booking->id}", ['status' => 'completed']);
        $invoice = BookingInvoice::where('booking_id', $booking->id)->first();

        $this->postJson("/api/invoice/{$invoice->id}/mark-paid")->assertStatus(200);
        $this->postJson("/api/invoice/{$invoice->id}/mark-paid")->assertStatus(409);
    }

    public function test_pdf_endpoint_returns_pdf_stream(): void
    {
        $shop = Shop::factory()->create(['name' => 'Test Shop']);
        $staff = Staff::factory()->create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $booking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'status' => 'booked', 'charges' => 100,
            'customer_name' => 'Layla',
            'services' => [
                ['title' => 'Haircut', 'price' => 50],
                ['title' => 'Beard trim', 'price' => 50],
            ],
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);
        $this->putJson("/api/booking/{$booking->id}", ['status' => 'completed']);

        $response = $this->get("/api/booking/{$booking->id}/invoice/pdf");
        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }
}
