<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_list_update_deactivate_staff_for_a_shop(): void
    {
        $shop = Shop::factory()->create();

        // Create
        $createResp = $this->postJson("/api/shops/{$shop->id}/staff", ['name' => 'Ali']);
        $createResp->assertStatus(201)
            ->assertJsonPath('data.name', 'Ali')
            ->assertJsonPath('data.is_active', true);

        $id = $createResp->json('data.id');

        // List
        $this->getJson("/api/shops/{$shop->id}/staff")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Update name
        $this->putJson("/api/shops/{$shop->id}/staff/{$id}", ['name' => 'Ali B.'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Ali B.');

        // Deactivate
        $this->putJson("/api/shops/{$shop->id}/staff/{$id}", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_create_staff_requires_name(): void
    {
        $shop = Shop::factory()->create();
        $this->postJson("/api/shops/{$shop->id}/staff", [])
            ->assertStatus(422);
    }

    public function test_activating_a_previously_inactive_staff_promotes_queued_bookings(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $shop = Shop::factory()->create();
        $inactive = Staff::factory()->inactive()->create(['shop_id' => $shop->id]);

        $queued = \App\Models\Booking::factory()->queued()->create([
            'shop_id' => $shop->id,
            'date' => '2026-05-11', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
        ]);

        $this->putJson("/api/shops/{$shop->id}/staff/{$inactive->id}", ['is_active' => true])
            ->assertStatus(200);

        $queued->refresh();
        $this->assertEquals($inactive->id, $queued->staff_id);
        $this->assertEquals('booked', strtolower($queued->status));
    }
}
