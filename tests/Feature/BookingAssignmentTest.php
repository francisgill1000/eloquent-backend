<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // stub Notify::push outbound HTTP
    }

    public function test_book_slot_assigns_to_only_free_staff(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);

        // 2026-05-11 is Monday. Default working hours are Mon-Sat only,
        // so picking a weekday avoids getWorkingHourOrFail throwing 400.
        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson("/api/shops/{$shop->id}/book", [
                'date' => '2026-05-11',
                'start_time' => '10:00:00',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('bookings', [
            'shop_id' => $shop->id,
            'staff_id' => $staff->id,
            'status' => 'booked',
            'date' => '2026-05-11',
            'start_time' => '10:00:00',
        ]);
    }

    public function test_book_slot_queues_when_all_staff_busy(): void
    {
        $shop = Shop::factory()->create();
        $staff = Staff::factory()->create(['shop_id' => $shop->id]);

        // Pre-book the only staff at 10:00 on Monday
        \App\Models\Booking::factory()->create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-2'])
            ->postJson("/api/shops/{$shop->id}/book", [
                'date' => '2026-05-11',
                'start_time' => '10:00:00',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('bookings', [
            'shop_id' => $shop->id,
            'staff_id' => null,
            'status' => 'queued',
            'date' => '2026-05-11',
            'start_time' => '10:00:00',
            'device_id' => 'dev-2',
        ]);
    }
}
