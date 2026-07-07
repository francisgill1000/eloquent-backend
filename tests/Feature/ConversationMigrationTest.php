<?php
namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\AssistantMessage;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'S'.$code, 'shop_code' => $code, 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_schema_links_messages_to_conversations(): void
    {
        // Column + relations exist and a message can carry a conversation_id.
        $shop = $this->shop('1001');
        $c = Conversation::create(['shop_id' => $shop->id, 'title' => 'Previous chat']);
        $m = AssistantMessage::create(['shop_id' => $shop->id, 'conversation_id' => $c->id, 'role' => 'user', 'content' => 'hi']);

        $this->assertSame($c->id, $m->fresh()->conversation->id);
        $this->assertSame(1, $c->messages()->count());
    }

    public function test_conversation_delete_cascades_messages_and_audio(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $shop = $this->shop('1001');
        $c = Conversation::create(['shop_id' => $shop->id, 'title' => 'Previous chat']);
        $m = AssistantMessage::create([
            'shop_id' => $shop->id, 'conversation_id' => $c->id, 'role' => 'assistant', 'content' => 'hi',
            'audio_path' => "assistant/{$shop->id}/{$c->id}/x.ogg", 'audio_mime' => 'audio/ogg',
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put($m->audio_path, 'BYTES');

        $c->delete();

        $this->assertDatabaseCount('assistant_messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing("assistant/{$shop->id}/{$c->id}/x.ogg");
    }
}
