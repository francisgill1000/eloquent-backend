<?php
namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Shop;
use App\Services\Assistant\ConversationStore;
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

    public function test_append_stores_row_and_audio_file(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $store = app(ConversationStore::class);

        $msg = $store->append($shop, 'assistant', 'fifty dirhams', 'OGGBYTES', 'audio/ogg');

        $this->assertDatabaseHas('assistant_messages', ['id' => $msg->id, 'shop_id' => $shop->id, 'role' => 'assistant', 'content' => 'fifty dirhams']);
        $this->assertNotNull($msg->audio_path);
        Storage::disk('local')->assertExists($msg->audio_path);
        $this->assertStringEndsWith('.ogg', $msg->audio_path);
    }

    public function test_append_without_audio_leaves_path_null(): void
    {
        Storage::fake('local');
        $store = app(ConversationStore::class);
        $msg = $store->append($this->shop(), 'user', 'how much today');
        $this->assertNull($msg->audio_path);
    }

    public function test_context_for_caps_and_orders(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        for ($i = 0; $i < 25; $i++) {
            $store->append($shop, $i % 2 === 0 ? 'user' : 'assistant', "m{$i}");
        }
        $ctx = $store->contextFor($shop, 20);
        $this->assertCount(20, $ctx);
        $this->assertSame('m5', $ctx[0]['content']);   // oldest kept
        $this->assertSame('m24', $ctx[19]['content']);  // newest last
        $this->assertSame(['role', 'content'], array_keys($ctx[0]));
    }

    public function test_clear_deletes_rows_and_audio(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $msg = $store->append($shop, 'assistant', 'hi', 'BYTES', 'audio/ogg');
        $path = $msg->audio_path;

        $store->clear($shop);

        $this->assertDatabaseCount('assistant_messages', 0);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_signed_url_null_without_audio(): void
    {
        $store = app(ConversationStore::class);
        $msg = $store->append($this->shop(), 'user', 'text only');
        $this->assertNull($store->signedUrl($msg));
    }
}
