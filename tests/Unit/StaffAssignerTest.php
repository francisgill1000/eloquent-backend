<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\StaffAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAssignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_picks_only_active_staff_when_one_is_inactive(): void
    {
        $shop = Shop::factory()->create();
        $inactive = Staff::factory()->inactive()->create(['shop_id' => $shop->id]);
        $active = Staff::factory()->create(['shop_id' => $shop->id]);

        $picked = (new StaffAssigner())->pickStaffForSlot(
            shopId: $shop->id,
            date: '2026-05-10',
            startTime: '10:00:00'
        );

        $this->assertEquals($active->id, $picked?->id);
    }

    public function test_picks_staff_with_fewest_bookings_today(): void
    {
        $shop = Shop::factory()->create();
        $busy = Staff::factory()->create(['shop_id' => $shop->id, 'name' => 'Busy']);
        $light = Staff::factory()->create(['shop_id' => $shop->id, 'name' => 'Light']);

        // Busy already has 2 bookings today
        Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $busy->id,
            'date' => '2026-05-10', 'start_time' => '09:00:00', 'end_time' => '09:30:00',
        ]);
        Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $busy->id,
            'date' => '2026-05-10', 'start_time' => '09:30:00', 'end_time' => '10:00:00',
        ]);

        $picked = (new StaffAssigner())->pickStaffForSlot(
            shopId: $shop->id,
            date: '2026-05-10',
            startTime: '11:00:00'
        );

        $this->assertEquals($light->id, $picked->id);
    }

    public function test_tie_break_by_lowest_id_when_counts_equal(): void
    {
        $shop = Shop::factory()->create();
        $first = Staff::factory()->create(['shop_id' => $shop->id]);
        $second = Staff::factory()->create(['shop_id' => $shop->id]);
        // Both have zero bookings today

        $picked = (new StaffAssigner())->pickStaffForSlot(
            shopId: $shop->id,
            date: '2026-05-10',
            startTime: '11:00:00'
        );

        $this->assertEquals($first->id, $picked->id);
    }

    public function test_returns_null_when_all_active_staff_busy_at_slot(): void
    {
        $shop = Shop::factory()->create();
        $a = Staff::factory()->create(['shop_id' => $shop->id]);
        $b = Staff::factory()->create(['shop_id' => $shop->id]);

        Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $a->id,
            'date' => '2026-05-10', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);
        Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $b->id,
            'date' => '2026-05-10', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $picked = (new StaffAssigner())->pickStaffForSlot(
            shopId: $shop->id,
            date: '2026-05-10',
            startTime: '10:00:00'
        );

        $this->assertNull($picked);
    }

    public function test_returns_null_when_shop_has_no_active_staff(): void
    {
        $shop = Shop::factory()->create();
        Staff::factory()->inactive()->create(['shop_id' => $shop->id]);

        $picked = (new StaffAssigner())->pickStaffForSlot(
            shopId: $shop->id,
            date: '2026-05-10',
            startTime: '10:00:00'
        );

        $this->assertNull($picked);
    }

    public function test_sweep_promotes_oldest_queued_booking_for_freed_slot(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);

        // Two queued bookings on the same slot, in known order
        $older = Booking::factory()->queued()->create([
            'shop_id' => $shop->id,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
            'created_at' => now()->subMinutes(10),
        ]);
        $newer = Booking::factory()->queued()->create([
            'shop_id' => $shop->id,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
            'created_at' => now()->subMinutes(2),
        ]);

        $promoted = (new StaffAssigner())->sweep(
            shopId: $shop->id,
            date: '2026-05-11',
            startTime: '10:00:00'
        );

        $this->assertCount(1, $promoted);
        $this->assertEquals($older->id, $promoted[0]->id);

        $older->refresh();
        $newer->refresh();

        $this->assertEquals($staff->id, $older->staff_id);
        $this->assertEquals('booked', strtolower($older->status));
        $this->assertNull($newer->staff_id);
        $this->assertEquals('queued', strtolower($newer->status));
    }

    public function test_sweep_promotes_nothing_when_no_staff_free(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $shop = Shop::factory()->create();
        // No active staff at all
        Booking::factory()->queued()->create([
            'shop_id' => $shop->id,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $promoted = (new StaffAssigner())->sweep(
            shopId: $shop->id,
            date: '2026-05-11',
            startTime: '10:00:00'
        );

        $this->assertEmpty($promoted);
    }
}
