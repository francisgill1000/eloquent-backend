<?php
namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantConversationApiTest extends TestCase
{
    use RefreshDatabase;

    private function authShop(string $code = '1001'): Shop
    {
        $shop = Shop::create(['name' => 'FreshPress'.$code, 'shop_code' => $code, 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);
        return $shop;
    }

    public function test_text_turn_is_persisted_and_history_returns_it(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Fifty dirhams.']]])]);

        $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])
            ->assertCreated()
            ->assertJsonPath('reply_text', 'Fifty dirhams.');

        $res = $this->getJson('/api/shop/assistant/history')->assertOk();
        $res->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.0.content', 'how much today')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonPath('messages.1.content', 'Fifty dirhams.')
            ->assertJsonPath('messages.0.audio_url', null);
    }

    public function test_voice_turn_persists_both_audios_and_serves_them(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'how much today']),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Fifty dirhams.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGGBYTES', 200),
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'VOICE', 'audio/webm');
        $res = $this->post('/api/shop/assistant/voice', ['audio' => $audio])
            ->assertCreated()
            ->assertJsonPath('transcript', 'how much today')
            ->assertJsonPath('reply_text', 'Fifty dirhams.');
        $this->assertNotNull($res->json('reply_audio_url'));

        $this->assertDatabaseCount('assistant_messages', 2);
        foreach (AssistantMessage::all() as $m) {
            Storage::disk('local')->assertExists($m->audio_path);
        }

        $this->get($res->json('reply_audio_url'))->assertOk();
    }

    public function test_audio_endpoint_rejects_unsigned_request(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $msg = AssistantMessage::create([
            'shop_id' => $shop->id, 'role' => 'assistant', 'content' => 'hi',
            'audio_path' => 'assistant/'.$shop->id.'/x.ogg', 'audio_mime' => 'audio/ogg',
        ]);
        Storage::disk('local')->put($msg->audio_path, 'BYTES');

        $this->get('/api/shop/assistant/audio/'.$msg->id)->assertForbidden();
    }

    public function test_transcription_failure_persists_nothing(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response('', 500)]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'VOICE', 'audio/webm');
        $this->post('/api/shop/assistant/voice', ['audio' => $audio])
            ->assertCreated()
            ->assertJsonPath('transcript', '');

        $this->assertDatabaseCount('assistant_messages', 0);
    }

    public function test_claude_failure_persists_nothing(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])->assertCreated();
        $this->assertDatabaseCount('assistant_messages', 0);
    }

    public function test_history_is_scoped_to_the_shop(): void
    {
        Storage::fake('local');
        $this->authShop('1001');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'A reply']]])]);
        $this->postJson('/api/shop/assistant/text', ['text' => 'a question'])->assertCreated();

        $this->authShop('2002'); // switches acting shop
        $this->getJson('/api/shop/assistant/history')
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }

    public function test_clear_deletes_history_and_audio(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $msg = AssistantMessage::create([
            'shop_id' => $shop->id, 'role' => 'assistant', 'content' => 'hi',
            'audio_path' => 'assistant/'.$shop->id.'/x.ogg', 'audio_mime' => 'audio/ogg',
        ]);
        Storage::disk('local')->put($msg->audio_path, 'BYTES');

        $this->deleteJson('/api/shop/assistant/history')->assertOk();

        $this->assertDatabaseCount('assistant_messages', 0);
        Storage::disk('local')->assertMissing('assistant/'.$shop->id.'/x.ogg');
    }
}
