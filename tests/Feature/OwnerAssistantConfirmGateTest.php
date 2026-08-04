<?php
namespace Tests\Feature;

use App\Models\AssistantPendingAction;
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

    public function test_a_destructive_turn_returns_a_confirm_card_instead_of_writing(): void
    {
        Storage::fake('public');
        $shop = Shop::create(['name' => 'A', 'shop_code' => '1', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);
        DB::table('bookings')->insert([
            'shop_id' => $shop->id, 'date' => now()->toDateString(), 'start_time' => '10:00',
            'end_time' => '10:30', 'status' => 'booked', 'charges' => 10, 'discount_amount' => 0,
            'services' => '[]', 'booking_reference' => 'BK00001', 'customer_name' => 'X',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'cancel_booking', 'input' => ['reference' => 'BK00001', 'confirmed' => true]]]])
                ->push(['content' => [['type' => 'text', 'text' => 'Cancel BK00001? Confirm below.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGG', 200),
        ]);

        // Even with confirmed:true from the model, a destructive tool must not write.
        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'cancel BK00001'])->assertCreated();
        $this->assertSame('booked', DB::table('bookings')->where('booking_reference', 'BK00001')->value('status'));

        $res->assertJsonPath('action.type', 'confirm')->assertJsonPath('action.destructive', true);
        $row = AssistantPendingAction::firstOrFail();
        $this->assertSame('cancel_booking', $row->tool);
        $this->assertSame($res->json('action.id'), $row->id);

        // The owner taps Confirm — now it writes.
        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();
        $this->assertSame('cancelled', DB::table('bookings')->where('booking_reference', 'BK00001')->value('status'));
    }

    /** A second preview in the same turn is still just a preview — and the owner still gets a card. */
    public function test_a_destructive_tool_previewed_twice_in_one_turn_still_returns_a_card(): void
    {
        Storage::fake('public');
        $shop = Shop::create(['name' => 'C', 'shop_code' => '3', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);
        DB::table('staff')->insert(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $toolUse = ['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'delete_staff', 'input' => ['name' => 'Ali', 'confirmed' => true]]]];
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($toolUse)
                ->push($toolUse)
                ->push(['content' => [['type' => 'text', 'text' => 'Delete Ali? Confirm below.']]]),
        ]);

        $this->postJson('/api/shop/assistant/text', ['text' => 'delete Ali'])
            ->assertCreated()
            ->assertJsonPath('action.type', 'confirm')
            ->assertJsonPath('reply_text', 'Delete Ali? Confirm below.');

        $this->assertSame(1, DB::table('staff')->where('shop_id', $shop->id)->count());
    }

    /**
     * Worst case: the model keeps re-calling until toolLoop's budget is gone and
     * it returns ''. The owner must still get the card — otherwise the turn
     * dead-ends on a generic apology with pending rows nobody can act on, which
     * is exactly the failure this feature exists to remove.
     */
    public function test_an_exhausted_tool_loop_still_returns_the_confirm_card(): void
    {
        Storage::fake('public');
        $shop = Shop::create(['name' => 'D', 'shop_code' => '4', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);
        DB::table('staff')->insert(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $toolUse = ['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'delete_staff', 'input' => ['name' => 'Ali', 'confirmed' => true]]]];
        $sequence = Http::sequence();
        for ($i = 0; $i < 5; $i++) { // maxTurns
            $sequence->push($toolUse);
        }
        Http::fake(['api.anthropic.com/*' => $sequence]);

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'delete Ali'])->assertCreated();

        $this->assertStringContainsString("couldn't work that out", $res->json('reply_text'));
        $res->assertJsonPath('action.type', 'confirm')->assertJsonPath('action.destructive', true);

        // The card the owner is handed is a real, live row they can still apply.
        $row = AssistantPendingAction::findOrFail($res->json('action.id'));
        $this->assertTrue($row->isLive());
        $this->assertSame(1, DB::table('staff')->where('shop_id', $shop->id)->count());
    }

    public function test_the_pending_row_is_tied_to_the_thread_the_turn_created(): void
    {
        Storage::fake('public');
        $shop = Shop::create(['name' => 'B', 'shop_code' => '2', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'create_staff', 'input' => ['name' => 'Jhon']]]])
                ->push(['content' => [['type' => 'text', 'text' => 'Add Jhon? Confirm below.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGG', 200),
        ]);

        // First turn of a brand-new chat: the conversation is created lazily
        // AFTER the tool ran, so the row must be backfilled with its id.
        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'add staff Jhon'])->assertCreated();

        $this->assertSame($res->json('conversation_id'), AssistantPendingAction::firstOrFail()->conversation_id);
    }
}
