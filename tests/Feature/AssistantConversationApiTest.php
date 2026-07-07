<?php
namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Conversation;
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

    private function fakeClaude(string $reply = 'Fifty dirhams.'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $reply]]])]);
    }

    public function test_first_text_lazily_creates_a_thread_titled_from_message(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $this->fakeClaude();

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])
            ->assertCreated()
            ->assertJsonPath('reply_text', 'Fifty dirhams.')
            ->assertJsonPath('title', 'how much today');
        $cid = $res->json('conversation_id');
        $this->assertIsInt($cid);

        $this->assertDatabaseHas('conversations', ['id' => $cid, 'shop_id' => $shop->id, 'title' => 'how much today']);
        $this->getJson("/api/shop/assistant/conversations/{$cid}")
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'how much today')
            ->assertJsonPath('messages.1.content', 'Fifty dirhams.');
    }

    public function test_second_turn_appends_to_same_thread_with_isolated_context(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $this->fakeClaude();

        $cid = $this->postJson('/api/shop/assistant/text', ['text' => 'first'])->json('conversation_id');
        // A separate thread whose messages must NOT leak into the first thread.
        $other = Conversation::create(['shop_id' => $shop->id, 'title' => 'Other']);
        AssistantMessage::create(['shop_id' => $shop->id, 'conversation_id' => $other->id, 'role' => 'user', 'content' => 'LEAK']);

        $this->postJson('/api/shop/assistant/text', ['text' => 'second', 'conversation_id' => $cid])
            ->assertCreated()
            ->assertJsonPath('conversation_id', $cid);

        $msgs = $this->getJson("/api/shop/assistant/conversations/{$cid}")->json('messages');
        $this->assertCount(4, $msgs); // first Q/A + second Q/A, no LEAK
        $this->assertNotContains('LEAK', array_column($msgs, 'content'));
    }

    public function test_claude_failure_creates_no_thread_and_no_messages(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])->assertCreated();
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('assistant_messages', 0);
    }

    public function test_conversation_list_is_scoped_and_newest_first(): void
    {
        Storage::fake('local');
        $this->authShop('1001');
        $this->fakeClaude();
        $this->postJson('/api/shop/assistant/text', ['text' => 'older'])->assertCreated();
        $this->postJson('/api/shop/assistant/text', ['text' => 'newer'])->assertCreated();

        $list = $this->getJson('/api/shop/assistant/conversations')->assertOk()->json('conversations');
        $this->assertCount(2, $list);
        $this->assertSame('newer', $list[0]['title']);

        $this->authShop('2002'); // different shop sees none
        $this->getJson('/api/shop/assistant/conversations')->assertOk()->assertJsonCount(0, 'conversations');
    }

    public function test_conversation_list_paginates_and_searches(): void
    {
        Storage::fake('local');
        $shop = $this->authShop('1001');
        // 25 plain threads + one searchable one (created directly; no Claude round-trips).
        for ($i = 0; $i < 25; $i++) {
            Conversation::create(['shop_id' => $shop->id, 'title' => "Thread {$i}"]);
        }
        Conversation::create(['shop_id' => $shop->id, 'title' => 'Special revenue']);

        // Page 1: 20 items, more to come.
        $this->getJson('/api/shop/assistant/conversations')
            ->assertOk()
            ->assertJsonCount(20, 'conversations')
            ->assertJsonPath('has_more', true);

        // Page 2: the remaining 6, no more.
        $this->getJson('/api/shop/assistant/conversations?page=2')
            ->assertOk()
            ->assertJsonCount(6, 'conversations')
            ->assertJsonPath('has_more', false);

        // Server-side search finds the one matching thread across all pages.
        $this->getJson('/api/shop/assistant/conversations?q=revenue')
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.title', 'Special revenue')
            ->assertJsonPath('has_more', false);
    }

    public function test_rename_and_delete_are_shop_scoped(): void
    {
        Storage::fake('local');
        $shop = $this->authShop('1001');
        $this->fakeClaude();
        $cid = $this->postJson('/api/shop/assistant/text', ['text' => 'hello'])->json('conversation_id');

        $this->patchJson("/api/shop/assistant/conversations/{$cid}", ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('title', 'Renamed');
        $this->assertDatabaseHas('conversations', ['id' => $cid, 'title' => 'Renamed']);

        // Another shop cannot touch it.
        $this->authShop('2002');
        $this->patchJson("/api/shop/assistant/conversations/{$cid}", ['title' => 'Hijack'])->assertNotFound();
        $this->deleteJson("/api/shop/assistant/conversations/{$cid}")->assertNotFound();
        $this->getJson("/api/shop/assistant/conversations/{$cid}")->assertNotFound();

        // Owner can delete it.
        Sanctum::actingAs($shop, ['*']);
        $this->deleteJson("/api/shop/assistant/conversations/{$cid}")->assertOk();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_voice_turn_persists_both_audios_under_conversation_and_serves_them(): void
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
        $this->assertIsInt($res->json('conversation_id'));

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
        $c = Conversation::create(['shop_id' => $shop->id, 'title' => 'T']);
        $msg = AssistantMessage::create([
            'shop_id' => $shop->id, 'conversation_id' => $c->id, 'role' => 'assistant', 'content' => 'hi',
            'audio_path' => 'assistant/'.$shop->id.'/'.$c->id.'/x.ogg', 'audio_mime' => 'audio/ogg',
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
        $this->assertDatabaseCount('conversations', 0);
    }
}
