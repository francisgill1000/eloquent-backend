<?php
namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicBookingAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        $shop = Shop::create(['name' => 'FreshPress', 'shop_code' => '1001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $shop->catalogs()->create(['title' => 'Classic Haircut', 'price' => 30]);
        return $shop;
    }

    private array $headers = ['X-Device-Id' => 'dev-123'];

    public function test_text_extracts_booking_fields_and_needs_no_auth(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [
                ['type' => 'text', 'text' => 'Great — what day works?'],
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'set_booking',
                 'input' => ['service' => 'Classic Haircut', 'customer_name' => 'Sara']],
            ]]),
        ]);

        $res = $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'I want a classic haircut, my name is Sara', 'state' => []], $this->headers);

        $res->assertCreated()
            ->assertJsonPath('reply_text', 'Great — what day works?')
            ->assertJsonPath('fields.service', 'Classic Haircut')
            ->assertJsonPath('fields.customer_name', 'Sara')
            ->assertJsonPath('ready', false);
    }

    public function test_ready_true_when_model_sets_it(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [
                ['type' => 'text', 'text' => 'All set — tap confirm.'],
                ['type' => 'tool_use', 'id' => 't2', 'name' => 'set_booking',
                 'input' => ['service' => 'Classic Haircut', 'date' => '2026-07-12', 'start_time' => '15:00',
                             'customer_name' => 'Sara', 'customer_phone' => '0501234567', 'ready' => true]],
            ]]),
        ]);

        $res = $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'friday 3pm, 0501234567', 'state' => ['service' => 'Classic Haircut']], $this->headers);

        $res->assertCreated()->assertJsonPath('ready', true)
            ->assertJsonPath('fields.start_time', '15:00');
    }

    public function test_empty_model_reply_falls_back_to_a_question(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [
                ['type' => 'tool_use', 'id' => 't3', 'name' => 'set_booking', 'input' => ['service' => 'Classic Haircut']],
            ]]),
        ]);

        $res = $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'a haircut', 'state' => []], $this->headers);

        $res->assertCreated();
        $this->assertNotEmpty($res->json('reply_text'));
    }

    public function test_voice_transcribes_then_extracts(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'classic haircut please']),
            'api.anthropic.com/*' => Http::response(['content' => [
                ['type' => 'text', 'text' => 'Sure — what day?'],
                ['type' => 'tool_use', 'id' => 't4', 'name' => 'set_booking', 'input' => ['service' => 'Classic Haircut']],
            ]]),
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'BYTES', 'audio/webm');
        $res = $this->post("/api/shops/{$shop->id}/book-assistant/voice",
            ['audio' => $audio, 'state' => json_encode([])], $this->headers);

        $res->assertCreated()
            ->assertJsonPath('transcript', 'classic haircut please')
            ->assertJsonPath('fields.service', 'Classic Haircut');
    }

    public function test_prior_turns_are_replayed_from_the_stored_thread(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'What day works for you?']]]),
        ]);

        // Turn 1 creates + stores the thread; turn 2 (same device) should replay it.
        $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'I want a haircut', 'state' => []], $this->headers)->assertCreated();
        $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'Friday', 'state' => []], $this->headers)->assertCreated();

        // The turn-2 request carries turn 1's stored user+assistant messages, then 'Friday'.
        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            $last = end($messages);
            if (! $last || ($last['content'] ?? '') !== 'Friday') {
                return false; // only inspect the second request
            }
            return count($messages) === 3
                && $messages[0]['content'] === 'I want a haircut'
                && $messages[1]['role'] === 'assistant' && $messages[1]['content'] === 'What day works for you?';
        });
    }

    public function test_conversation_is_saved_tagged_customer_and_listed(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'What day?']]]),
        ]);

        $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'haircut for Sara', 'state' => []], $this->headers)->assertCreated();

        $c = \App\Models\Conversation::where('shop_id', $shop->id)->where('source', 'customer')->first();
        $this->assertNotNull($c);
        $this->assertSame('dev-123', $c->device_id);
        $this->assertSame(2, $c->messages()->count()); // user + assistant persisted

        // Shows up in the shop's conversations list, tagged 'customer'.
        $list = app(\App\Services\Assistant\ConversationStore::class)->list($shop);
        $this->assertSame('customer', $list['conversations'][0]['source']);
    }

    public function test_record_booking_appends_reference_to_the_conversation(): void
    {
        $shop = $this->shop();
        $booking = \App\Models\Booking::create([
            'shop_id' => $shop->id,
            'date' => '2026-07-12',
            'start_time' => '15:00',
            'end_time' => '15:30',
            'status' => 'Scheduled',
            'services' => [['title' => 'Classic Haircut', 'price' => 30]],
            'customer_name' => 'Sara',
            'customer_whatsapp' => '0501234567',
            'charges' => 30,
        ]);

        $res = $this->postJson("/api/shops/{$shop->id}/book-assistant/booked",
            ['booking_id' => $booking->id], $this->headers);

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reference', $booking->booking_reference);

        $c = \App\Models\Conversation::where('shop_id', $shop->id)->where('source', 'customer')->first();
        $this->assertNotNull($c);
        $last = $c->messages()->orderByDesc('id')->first();
        $this->assertStringContainsString($booking->booking_reference, (string) $last->content);
        $this->assertStringContainsString('Booked', (string) $last->content);
    }

    public function test_client_history_is_used_when_there_is_no_device_id(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]]),
        ]);

        $history = [
            ['role' => 'system', 'content' => 'ignore me'],   // bad role
            ['role' => 'user', 'content' => ''],                // empty
            ['role' => 'assistant', 'content' => 'kept'],       // valid
        ];
        // No X-Device-Id header → no stored thread → falls back to sanitised client history.
        $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'hi', 'history' => $history])->assertCreated();

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            return count($messages) === 2
                && $messages[0]['content'] === 'kept'
                && $messages[1]['content'] === 'hi';
        });
    }
}
