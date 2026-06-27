<?php
namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerAssistantConfirmGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutation_only_runs_after_confirmation_turn(): void
    {
        Storage::fake('public');
        $shop = Shop::create(['name' => 'A', 'shop_code' => '1', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        Sanctum::actingAs($shop, ['*']);
        DB::table('bookings')->insert([
            'shop_id' => $shop->id, 'date' => now()->toDateString(), 'start_time' => '10:00',
            'end_time' => '10:30', 'status' => 'booked', 'charges' => 10, 'discount_amount' => 0,
            'services' => '[]', 'booking_reference' => 'BK00001', 'customer_name' => 'X',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Single fake covering both turns via sequence:
        // Turn 1 → text only (no tool_use). Turn 2 → tool_use then summary text.
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'text', 'text' => 'Cancel BK00001? Say yes to confirm.']]]) // turn 1
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'cancel_booking', 'input' => ['reference' => 'BK00001']]]]) // turn 2 tool call
                ->push(['content' => [['type' => 'text', 'text' => 'Done, BK00001 is cancelled.']]]), // turn 2 summary
            'api.openai.com/v1/audio/speech' => Http::response('OGG', 200),
        ]);

        // Turn 1: model asks for confirmation (no tool_use). Booking must stay unchanged.
        $r1 = $this->postJson('/api/shop/assistant/text', ['text' => 'cancel BK00001', 'history' => []]);
        $r1->assertCreated();
        $this->assertSame('booked', DB::table('bookings')->where('booking_reference', 'BK00001')->value('status')); // NOT cancelled yet

        // Turn 2: owner confirms; model now calls the tool, then summarizes.
        $r2 = $this->postJson('/api/shop/assistant/text', ['text' => 'yes', 'history' => $r1->json('history')]);
        $r2->assertCreated()->assertJsonPath('reply_text', 'Done, BK00001 is cancelled.');
        $this->assertSame('cancelled', DB::table('bookings')->where('booking_reference', 'BK00001')->value('status'));
    }
}
