<?php
namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerAssistantOpenBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_booking_turn_returns_a_navigate_action(): void
    {
        Storage::fake('local');
        $shop = Shop::create(['name' => 'A', 'shop_code' => '7001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);

        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $b = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked', 'charges' => 40,
            'discount_amount' => 0, 'services' => [], 'customer_name' => 'Hina',
        ]);
        $b->update(['booking_reference' => 'BK00042']);

        // Turn: owner says "yes"; model calls open_booking, then summarizes.
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'open_booking', 'input' => ['reference' => 'BK00042']]]])
                ->push(['content' => [['type' => 'text', 'text' => 'Opening the booking now.']]]),
        ]);

        $this->postJson('/api/shop/assistant/text', ['text' => 'yes open it'])
            ->assertCreated()
            ->assertJsonPath('reply_text', 'Opening the booking now.')
            ->assertJsonPath('action.type', 'navigate')
            ->assertJsonPath('action.route', "/booking/{$b->id}");
    }

    public function test_normal_turn_has_no_action_key(): void
    {
        Storage::fake('local');
        $shop = Shop::create(['name' => 'B', 'shop_code' => '7002', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'You made fifty dirhams.']]])]);

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])->assertCreated();
        $this->assertArrayNotHasKey('action', $res->json());
    }
}
