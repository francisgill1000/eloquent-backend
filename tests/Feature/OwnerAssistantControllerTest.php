<?php
namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerAssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authShop(): Shop
    {
        $shop = Shop::create(['name' => 'FreshPress', 'shop_code' => '1001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        Sanctum::actingAs($shop, ['*']);
        return $shop;
    }

    public function test_text_endpoint_returns_reply_and_audio_url(): void
    {
        Storage::fake('public');
        $this->authShop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'You made 50 dirhams today.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('FAKE_OGG_BYTES', 200),
        ]);

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'how much today', 'history' => []]);

        $res->assertCreated()
            ->assertJsonPath('reply_text', 'You made 50 dirhams today.')
            ->assertJsonStructure(['reply_text', 'reply_audio_url', 'history'])
            ->assertJsonMissingPath('transcript');
        // Typed question → text reply, no spoken audio.
        $this->assertNull($res->json('reply_audio_url'));
    }

    public function test_text_endpoint_requires_auth(): void
    {
        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'hi']);
        $res->assertUnauthorized();
    }

    public function test_voice_endpoint_transcribes_then_replies(): void
    {
        Storage::fake('public');
        $this->authShop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'how much today']),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Fifty dirhams.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('FAKE_OGG_BYTES', 200),
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'FAKE-AUDIO-BYTES', 'audio/webm');
        $res = $this->post('/api/shop/assistant/voice', ['audio' => $audio, 'history' => '[]']);

        $res->assertCreated()
            ->assertJsonPath('transcript', 'how much today')
            ->assertJsonPath('reply_text', 'Fifty dirhams.');
        // Spoken question → spoken reply.
        $this->assertNotNull($res->json('reply_audio_url'));
    }

    public function test_claude_failure_degrades_gracefully(): void
    {
        Storage::fake('public');
        $this->authShop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'boom'], 500),
            'api.openai.com/v1/audio/speech' => Http::response('OGG', 200),
        ]);

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'how much today', 'history' => []]);

        $res->assertCreated();
        $this->assertNotEmpty($res->json('reply_text'));
    }

    public function test_transcription_failure_returns_graceful_message(): void
    {
        Storage::fake('public');
        $this->authShop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response('', 500),
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'FAKE-AUDIO-BYTES', 'audio/webm');
        $res = $this->post('/api/shop/assistant/voice', ['audio' => $audio, 'history' => '[]']);

        $res->assertCreated()
            ->assertJsonPath('transcript', '');
        $this->assertStringContainsStringIgnoringCase("didn't catch", $res->json('reply_text'));
    }

    public function test_empty_content_history_messages_are_not_sent_to_claude(): void
    {
        Storage::fake('public');
        $this->authShop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'how much today']),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGG', 200),
        ]);

        // A prior failed voice turn poisons the history with an empty-content
        // user message. The voice endpoint receives history as a JSON *string*,
        // so ConvertEmptyStringsToNull can't reach inside it — the empty content
        // survives to parseHistory. Anthropic rejects any empty-content message
        // with a 400, which breaks every subsequent turn, so it must be dropped.
        $history = json_encode([
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi there'],
            ['role' => 'user', 'content' => ''],
            ['role' => 'assistant', 'content' => "Sorry, I didn't catch that — please try again."],
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'FAKE-AUDIO-BYTES', 'audio/webm');
        $res = $this->post('/api/shop/assistant/voice', ['audio' => $audio, 'history' => $history]);
        $res->assertCreated();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return false;
            }
            foreach ($request['messages'] as $m) {
                if (is_string($m['content']) && trim($m['content']) === '') {
                    return false; // an empty-content message leaked through
                }
            }
            return true;
        });
    }

    public function test_voice_endpoint_requires_auth(): void
    {
        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'FAKE-AUDIO-BYTES', 'audio/webm');
        $res = $this->postJson('/api/shop/assistant/voice', ['audio' => $audio, 'history' => '[]']);

        $res->assertUnauthorized();
    }
}
