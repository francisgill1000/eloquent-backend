<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingDurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerServiceDurationTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithHours(int $slot = 30): Shop
    {
        $shop = Shop::factory()->create();
        DB::table('shop_working_hours')->updateOrInsert(
            ['shop_id' => $shop->id, 'day_of_week' => (int) now()->dayOfWeek],
            ['start_time' => '09:00:00', 'end_time' => '20:00:00', 'slot_duration' => $slot,
             'created_at' => now(), 'updated_at' => now()],
        );
        return $shop;
    }

    private function service(Shop $shop, array $attrs): Catalog
    {
        return $shop->catalogs()->create(array_merge(['title' => 'Svc', 'price' => 10], $attrs));
    }

    public function test_no_durations_set_falls_back_to_slot_duration(): void
    {
        $shop = $this->shopWithHours(30);
        $svc = $this->service($shop, []); // no duration
        $minutes = app(BookingDurationService::class)
            ->computeMinutes($shop, [['id' => $svc->id, 'title' => 'Svc']], 30);
        $this->assertSame(30, $minutes);
    }

    public function test_single_service_duration_plus_buffer(): void
    {
        $shop = $this->shopWithHours(30);
        $svc = $this->service($shop, ['duration_minutes' => 90, 'buffer_minutes' => 15]);
        $minutes = app(BookingDurationService::class)
            ->computeMinutes($shop, [['id' => $svc->id]], 30);
        $this->assertSame(105, $minutes);
    }

    public function test_two_services_durations_are_summed(): void
    {
        $shop = $this->shopWithHours(30);
        $a = $this->service($shop, ['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $b = $this->service($shop, ['duration_minutes' => 45, 'buffer_minutes' => 10]);
        $minutes = app(BookingDurationService::class)
            ->computeMinutes($shop, [['id' => $a->id], ['id' => $b->id]], 30);
        $this->assertSame(115, $minutes);
    }

    public function test_duration_lookup_is_tenant_scoped(): void
    {
        $shop = $this->shopWithHours(30);
        $other = Shop::factory()->create();
        $foreign = $this->service($other, ['duration_minutes' => 200]);
        // Another shop's catalog id must be ignored → falls back to slot.
        $minutes = app(BookingDurationService::class)
            ->computeMinutes($shop, [['id' => $foreign->id]], 30);
        $this->assertSame(30, $minutes);
    }

    public function test_booking_creator_sets_end_time_from_service_duration(): void
    {
        $shop = $this->shopWithHours(30);
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $svc = $this->service($shop, ['duration_minutes' => 60, 'buffer_minutes' => 0]);

        $booking = app(BookingCreator::class)->create($shop, [
            'customer_name' => 'Sara', 'customer_whatsapp' => '971500000000',
            'date' => now()->toDateString(), 'start_time' => '10:00',
            'services' => [['id' => $svc->id, 'title' => 'Svc']], 'charges' => 10,
        ]);

        $this->assertSame('11:00', \Carbon\Carbon::parse($booking->end_time)->format('H:i'));
    }

    public function test_catalog_crud_persists_duration_and_buffer(): void
    {
        $shop = $this->shopWithHours(30);
        $token = $shop->createToken('t')->plainTextToken;
        $this->startTrial($shop);

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/shop/catalogs', [
                'title' => 'Hair Colour', 'price' => 200,
                'duration_minutes' => 120, 'buffer_minutes' => 15,
            ])->assertCreated();

        $id = $res->json('data.id');
        $this->assertDatabaseHas('catalogs', [
            'id' => $id, 'duration_minutes' => 120, 'buffer_minutes' => 15,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson("/api/shop/catalogs/{$id}", ['duration_minutes' => 90])
            ->assertOk();
        $this->assertDatabaseHas('catalogs', ['id' => $id, 'duration_minutes' => 90]);
    }
}
