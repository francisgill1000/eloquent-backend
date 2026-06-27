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
            ->assertJsonStructure(['reply_text', 'reply_audio_url', 'history']);
        $this->assertNotNull($res->json('reply_audio_url'));
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

        $audio = UploadedFile::fake()->create('voice.webm', 10, 'audio/webm');
        $res = $this->post('/api/shop/assistant/voice', ['audio' => $audio, 'history' => '[]']);

        $res->assertCreated()
            ->assertJsonPath('transcript', 'how much today')
            ->assertJsonPath('reply_text', 'Fifty dirhams.');
    }
}
