<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeNotesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(Shop $shop, array $attrs = []): ShopCustomer
    {
        return ShopCustomer::create(array_merge([
            'shop_id' => $shop->id, 'name' => 'Aisha',
            'whatsapp' => '971555000111', 'whatsapp_normalized' => '971555000111',
        ], $attrs));
    }

    public function test_show_returns_customer_with_notes_preferences_and_summary(): void
    {
        $shop = Shop::factory()->create();
        $customer = $this->customer($shop, [
            'notes' => 'Allergic to ammonia', 'preferences' => ['stylist' => 'Sara'],
        ]);
        Booking::create([
            'shop_id' => $shop->id, 'shop_customer_id' => $customer->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'completed',
            'charges' => 80, 'services' => [], 'customer_name' => 'Aisha',
        ]);

        $res = $this->getJson("/api/shops/{$shop->id}/customers/{$customer->id}")->assertOk();
        $res->assertJsonPath('data.notes', 'Allergic to ammonia');
        $res->assertJsonPath('data.preferences.stylist', 'Sara');
        $res->assertJsonPath('data.bookings_count', 1);
        $this->assertEqualsWithDelta(80.0, $res->json('data.total_spent'), 0.01);
    }

    public function test_update_customer_notes_and_preferences_round_trip_and_tenant_scoped(): void
    {
        $shop = Shop::factory()->create();
        $customer = $this->customer($shop);

        $this->patchJson("/api/shops/{$shop->id}/customers/{$customer->id}", [
            'notes' => 'Prefers morning slots',
            'preferences' => ['allergies' => 'none', 'hair_type' => 'curly'],
        ])->assertOk();

        $fresh = $customer->fresh();
        $this->assertSame('Prefers morning slots', $fresh->notes);
        $this->assertSame(['allergies' => 'none', 'hair_type' => 'curly'], $fresh->preferences);

        // Tenant scoping: another shop cannot read or write this customer.
        $other = Shop::factory()->create();
        $this->getJson("/api/shops/{$other->id}/customers/{$customer->id}")->assertNotFound();
        $this->patchJson("/api/shops/{$other->id}/customers/{$customer->id}", ['notes' => 'x'])
            ->assertNotFound();
    }

    public function test_lookup_includes_notes_and_preferences(): void
    {
        $shop = Shop::factory()->create();
        $this->customer($shop, [
            'notes' => 'VIP', 'preferences' => ['stylist' => 'Ali'],
        ]);

        $res = $this->getJson("/api/shops/{$shop->id}/customers/lookup?whatsapp=971555000111")->assertOk();
        $res->assertJsonPath('found', true);
        $res->assertJsonPath('notes', 'VIP');
        $res->assertJsonPath('preferences.stylist', 'Ali');
    }

    public function test_booking_notes_endpoint_persists_per_visit_notes(): void
    {
        $shop = Shop::factory()->create();
        $booking = Booking::create([
            'shop_id' => $shop->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked',
            'charges' => 0, 'services' => [], 'customer_name' => 'Aisha',
        ]);

        $this->patchJson("/api/booking/{$booking->id}/notes", ['notes' => 'First-time client, patch test done'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'First-time client, patch test done');

        $this->assertSame('First-time client, patch test done', $booking->fresh()->notes);
    }
}
