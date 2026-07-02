<?php
namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssistantMessageModelTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'FreshPress', 'shop_code' => '1001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_deleting_a_message_removes_its_audio_file(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        Storage::disk('local')->put('assistant/1/note.ogg', 'BYTES');

        $msg = AssistantMessage::create([
            'shop_id' => $shop->id,
            'role' => 'assistant',
            'content' => 'hi',
            'audio_path' => 'assistant/1/note.ogg',
            'audio_mime' => 'audio/ogg',
        ]);

        Storage::disk('local')->assertExists('assistant/1/note.ogg');
        $msg->delete();
        Storage::disk('local')->assertMissing('assistant/1/note.ogg');
    }
}
