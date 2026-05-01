<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueuePromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_cancelling_a_booking_promotes_a_queued_booking_for_same_slot(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);

        $assigned = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'status' => 'booked',
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);
        $queued = Booking::factory()->queued()->create([
            'shop_id' => $shop->id,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $response = $this->putJson("/api/booking/{$assigned->id}", ['status' => 'cancelled']);
        $response->assertStatus(200);

        $queued->refresh();
        $this->assertEquals($staff->id, $queued->staff_id);
        $this->assertEquals('booked', strtolower($queued->status));
    }

    public function test_marking_completed_also_promotes_a_queued_booking(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);

        $assigned = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'status' => 'booked',
            'date' => '2026-05-11', 'start_time' => '11:00:00', 'end_time' => '11:30:00',
        ]);
        $queued = Booking::factory()->queued()->create([
            'shop_id' => $shop->id,
            'date' => '2026-05-11', 'start_time' => '11:00:00', 'end_time' => '11:30:00',
        ]);

        $this->putJson("/api/booking/{$assigned->id}", ['status' => 'completed'])
            ->assertStatus(200);

        $queued->refresh();
        $this->assertEquals($staff->id, $queued->staff_id);
        $this->assertEquals('booked', strtolower($queued->status));
    }

    public function test_reassigning_a_booking_promotes_a_queued_booking_for_vacated_slot(): void
    {
        $shop = Shop::factory()->create();
        $ali = Staff::factory()->create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $sara = Staff::factory()->create(['shop_id' => $shop->id, 'name' => 'Sara']);

        // Ali holds 10:00, Sara is free
        $aliBooking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $ali->id, 'status' => 'booked',
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);
        // Queued booking on the same slot
        $queued = Booking::factory()->queued()->create([
            'shop_id' => $shop->id,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        // Reassign Ali's booking to Sara → Ali frees up at 10:00, queued promotes to Ali
        $this->postJson("/api/booking/{$aliBooking->id}/reassign", ['staff_id' => $sara->id])
            ->assertStatus(200);

        $aliBooking->refresh();
        $queued->refresh();

        $this->assertEquals($sara->id, $aliBooking->staff_id);
        $this->assertEquals($ali->id, $queued->staff_id);
        $this->assertEquals('booked', strtolower($queued->status));
    }

    public function test_reassign_fails_if_target_staff_already_busy_at_slot(): void
    {
        $shop = Shop::factory()->create();
        $ali = Staff::factory()->create(['shop_id' => $shop->id]);
        $sara = Staff::factory()->create(['shop_id' => $shop->id]);

        $aliBooking = Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $ali->id, 'status' => 'booked',
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);
        Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $sara->id, 'status' => 'booked',
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $this->postJson("/api/booking/{$aliBooking->id}/reassign", ['staff_id' => $sara->id])
            ->assertStatus(409);
    }
}
