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
}
