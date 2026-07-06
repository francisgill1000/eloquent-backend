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
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);
        return $shop;
    }

    public function test_text_endpoint_returns_reply_and_audio_url(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'You made 50 dirhams today.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('FAKE_OGG_BYTES', 200),
        ]);

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'how much today']);

        $res->assertCreated()
            ->assertJsonPath('reply_text', 'You made 50 dirhams today.')
            ->assertJsonStructure(['reply_text', 'reply_audio_url'])
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
        Storage::fake('local');
        $this->authShop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'how much today']),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Fifty dirhams.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('FAKE_OGG_BYTES', 200),
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'FAKE-AUDIO-BYTES', 'audio/webm');
        $res = $this->post('/api/shop/assistant/voice', ['audio' => $audio]);

        $res->assertCreated()
            ->assertJsonPath('transcript', 'how much today')
            ->assertJsonPath('reply_text', 'Fifty dirhams.');
        // Spoken question → spoken reply.
        $this->assertNotNull($res->json('reply_audio_url'));
    }

    public function test_claude_failure_degrades_gracefully(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'boom'], 500),
            'api.openai.com/v1/audio/speech' => Http::response('OGG', 200),
        ]);

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'how much today']);

        $res->assertCreated();
        $this->assertNotEmpty($res->json('reply_text'));
    }

    public function test_transcription_failure_returns_graceful_message(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response('', 500),
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'FAKE-AUDIO-BYTES', 'audio/webm');
        $res = $this->post('/api/shop/assistant/voice', ['audio' => $audio]);

        $res->assertCreated()
            ->assertJsonPath('transcript', '');
        $this->assertStringContainsStringIgnoringCase("didn't catch", $res->json('reply_text'));
    }

    public function test_voice_endpoint_requires_auth(): void
    {
        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'FAKE-AUDIO-BYTES', 'audio/webm');
        $res = $this->postJson('/api/shop/assistant/voice', ['audio' => $audio]);

        $res->assertUnauthorized();
    }
}
