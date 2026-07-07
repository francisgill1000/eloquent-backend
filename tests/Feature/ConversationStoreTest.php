<?php
namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Shop;
use App\Services\Assistant\ConversationStore;
// AssistantMessage rows are asserted via assertDatabaseHas/Count, not the model directly.
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationStoreTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code = '1001'): Shop
    {
        return Shop::create(['name' => 'S'.$code, 'shop_code' => $code, 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
    }

    private function conv(Shop $shop, string $title = 'T'): Conversation
    {
        return Conversation::create(['shop_id' => $shop->id, 'title' => $title]);
    }

    public function test_create_titles_from_first_message_truncated(): void
    {
        $store = app(ConversationStore::class);
        $shop = $this->shop();
        $long = str_repeat('a', 200);

        $c = $store->create($shop, "  How much   did I make? ");
        $this->assertSame('How much did I make?', $c->title);

        $c2 = $store->create($shop, $long);
        $this->assertSame(61, mb_strlen($c2->title)); // 60 chars + ellipsis
        $this->assertStringEndsWith('…', $c2->title);

        $c3 = $store->create($shop, '   ');
        $this->assertSame('New chat', $c3->title);
    }

    public function test_append_stores_row_audio_and_touches_conversation(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $c = $this->conv($shop);
        $store = app(ConversationStore::class);

        $before = $c->updated_at;
        $msg = $store->append($c, 'assistant', 'fifty dirhams', 'OGGBYTES', 'audio/ogg');

        $this->assertDatabaseHas('assistant_messages', [
            'id' => $msg->id, 'conversation_id' => $c->id, 'shop_id' => $shop->id, 'role' => 'assistant',
        ]);
        Storage::disk('local')->assertExists($msg->audio_path);
        $this->assertStringStartsWith("assistant/{$shop->id}/{$c->id}/", $msg->audio_path);
        $this->assertTrue($c->fresh()->updated_at->gte($before));
    }

    public function test_context_is_isolated_to_the_conversation(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $a = $this->conv($shop, 'A');
        $b = $this->conv($shop, 'B');

        $store->append($a, 'user', 'in-a');
        $store->append($b, 'user', 'in-b');

        $ctx = $store->contextFor($a);
        $this->assertCount(1, $ctx);
        $this->assertSame('in-a', $ctx[0]['content']);
        $this->assertSame(['role', 'content'], array_keys($ctx[0]));
    }

    public function test_context_caps_and_orders(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $c = $this->conv($shop);
        for ($i = 0; $i < 25; $i++) {
            $store->append($c, $i % 2 === 0 ? 'user' : 'assistant', "m{$i}");
        }
        $ctx = $store->contextFor($c, 20);
        $this->assertCount(20, $ctx);
        $this->assertSame('m5', $ctx[0]['content']);
        $this->assertSame('m24', $ctx[19]['content']);
    }

    public function test_list_returns_shop_threads_newest_first(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $old = $this->conv($shop, 'Old');
        $new = $this->conv($shop, 'New');
        // Make $new the most recently updated.
        $store->append($new, 'user', 'x');

        $list = $store->list($shop);
        $this->assertSame(['id', 'title', 'updated_at'], array_keys($list[0]));
        $this->assertSame($new->id, $list[0]['id']);
        $this->assertSame($old->id, $list[1]['id']);
    }

    public function test_messages_for_returns_chronological_api_shape(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $c = $this->conv($shop);
        $store->append($c, 'user', 'first');
        $store->append($c, 'assistant', 'second');

        $msgs = $store->messagesFor($c);
        $this->assertCount(2, $msgs);
        $this->assertSame('first', $msgs[0]['content']);
        $this->assertSame(['id', 'role', 'content', 'audio_url'], array_keys($msgs[0]));
    }

    public function test_rename_updates_title(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $c = $this->conv($shop, 'Old');
        $store->rename($c, 'New name');
        $this->assertSame('New name', $c->fresh()->title);
    }

    public function test_delete_removes_thread_messages_and_audio(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $c = $this->conv($shop);
        $msg = $store->append($c, 'assistant', 'hi', 'BYTES', 'audio/ogg');
        $path = $msg->audio_path;

        $store->delete($c);

        $this->assertDatabaseCount('assistant_messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        Storage::disk('local')->assertMissing($path);
    }
}
