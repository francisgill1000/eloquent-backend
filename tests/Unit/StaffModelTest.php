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
}
