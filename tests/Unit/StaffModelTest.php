<?php

namespace Tests\Unit;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_active_staff(): void
    {
        $staff = Staff::factory()->create(['shop_id' => 1, 'name' => 'Ali']);

        $this->assertDatabaseHas('staff', [
            'id' => $staff->id,
            'shop_id' => 1,
            'name' => 'Ali',
            'is_active' => true,
        ]);
    }

    public function test_staff_belongs_to_shop_and_has_bookings(): void
    {
        $shop = \App\Models\Shop::factory()->create();
        $staff = \App\Models\Staff::factory()->create(['shop_id' => $shop->id]);
        $booking = \App\Models\Booking::factory()->create([
            'shop_id' => $shop->id,
            'staff_id' => $staff->id,
        ]);

        $this->assertEquals($shop->id, $staff->shop->id);
        $this->assertEquals($staff->id, $booking->staff->id);
        $this->assertTrue($staff->bookings->contains($booking));
        $this->assertTrue($shop->staff->contains($staff));
    }
}
