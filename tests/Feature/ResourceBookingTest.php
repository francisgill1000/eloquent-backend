<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Catalog;
use App\Models\Resource;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResourceBookingTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithHours(): Shop
    {
        $shop = Shop::factory()->create();
        DB::table('shop_working_hours')->updateOrInsert(
            ['shop_id' => $shop->id, 'day_of_week' => (int) now()->dayOfWeek],
            ['start_time' => '09:00:00', 'end_time' => '20:00:00', 'slot_duration' => 30,
             'created_at' => now(), 'updated_at' => now()],
        );
        return $shop;
    }

    private function roomService(Shop $shop): Catalog
    {
        return $shop->catalogs()->create([
            'title' => 'Facial', 'price' => 100, 'requires_resource_type' => 'room',
        ]);
    }

    private function book(Shop $shop, Catalog $svc, string $customer): Booking
    {
        return app(BookingCreator::class)->create($shop, [
            'customer_name' => $customer, 'customer_whatsapp' => null,
            'date' => now()->toDateString(), 'start_time' => '10:00',
            'services' => [['id' => $svc->id, 'title' => $svc->title]], 'charges' => 100,
        ]);
    }

    public function test_service_without_resource_requirement_is_unaffected(): void
    {
        $shop = $this->shopWithHours();
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $svc = $shop->catalogs()->create(['title' => 'Cut', 'price' => 50]); // no resource

        $b = $this->book($shop, $svc, 'A');
        $this->assertSame('booked', strtolower($b->getRawOriginal('status')));
        $this->assertNull($b->resource_id);
    }

    public function test_booked_when_room_free_then_queued_when_room_busy(): void
    {
        $shop = $this->shopWithHours();
        // two staff so staff is never the limiting factor
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        Staff::create(['shop_id' => $shop->id, 'name' => 'Sara', 'is_active' => true]);
        $room = Resource::create(['shop_id' => $shop->id, 'name' => 'Room 1', 'type' => 'room', 'is_active' => true]);
        $svc = $this->roomService($shop);

        $first = $this->book($shop, $svc, 'First');
        $this->assertSame('booked', strtolower($first->getRawOriginal('status')));
        $this->assertSame($room->id, $first->resource_id);

        // Only one room → second concurrent booking must queue.
        $second = $this->book($shop, $svc, 'Second');
        $this->assertSame('queued', strtolower($second->getRawOriginal('status')));
        $this->assertNull($second->resource_id);
        $this->assertNull($second->staff_id);
    }

    public function test_freeing_the_room_sweeps_the_queued_booking_into_it(): void
    {
        $shop = $this->shopWithHours();
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        Staff::create(['shop_id' => $shop->id, 'name' => 'Sara', 'is_active' => true]);
        $room = Resource::create(['shop_id' => $shop->id, 'name' => 'Room 1', 'type' => 'room', 'is_active' => true]);
        $svc = $this->roomService($shop);

        $first = $this->book($shop, $svc, 'First');
        $second = $this->book($shop, $svc, 'Second');
        $this->assertSame('queued', strtolower($second->getRawOriginal('status')));

        // Complete the first → frees staff + room → sweep promotes the queued one.
        app(BookingStatusService::class)->apply($first, 'completed');

        $second->refresh();
        $this->assertSame('booked', strtolower($second->getRawOriginal('status')));
        $this->assertSame($room->id, $second->resource_id);
    }

    public function test_resource_crud_is_tenant_scoped(): void
    {
        $shop = Shop::factory()->create();
        $res = $this->postJson("/api/shops/{$shop->id}/resources", ['name' => 'Laser A', 'type' => 'machine'])
            ->assertCreated()->json('data');

        $this->getJson("/api/shops/{$shop->id}/resources")->assertOk()->assertJsonCount(1, 'data');

        // Another shop cannot mutate this shop's resource.
        $other = Shop::factory()->create();
        $this->putJson("/api/shops/{$other->id}/resources/{$res['id']}", ['name' => 'Hijack'])
            ->assertNotFound();
    }
}
