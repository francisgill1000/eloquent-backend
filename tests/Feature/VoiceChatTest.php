<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaReply;
use App\Models\Shop;
use App\Models\WaContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** In-app Live Chat voice: customer records audio, Whisper transcribes, the AI answers in voice + text. */
class VoiceChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'services.anthropic.key' => 'sk-test',
            'services.openai.key' => 'sk-oai',
            'services.webpush.public_key' => null,
        ]);
    }

    public function test_voice_upload_stores_audio_and_dispatches_reply(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();

        $res = $this->withHeader('X-Device-Id', 'dev-voice')
            ->postJson("/api/chat/shops/{$shop->id}/voice", [
                'audio' => UploadedFile::fake()->create('note.webm', 40, 'audio/webm'),
            ]);

        $res->assertCreated();
        $contact = WaContact::where('shop_id', $shop->id)->where('device_id', 'dev-voice')->firstOrFail();
        $message = $contact->messages()->first();
        $this->assertSame('audio', $message->type);
        $this->assertNotNull($message->media_path);
        Storage::disk('public')->assertExists($message->media_path);
        Queue::assertPushed(ProcessWaReply::class);
    }

    public function test_voice_upload_requires_an_audio_file(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();

        $this->withHeader('X-Device-Id', 'dev-voice')
            ->postJson("/api/chat/shops/{$shop->id}/voice", [
                'audio' => UploadedFile::fake()->create('hack.txt', 10, 'text/plain'),
            ])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_voice_message_is_transcribed_and_answered_with_voice_and_text(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'كم سعر القص؟']), // Arabic: how much is a haircut?
            'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'القص بـ 50 درهم 😊']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGG-OPUS-BYTES'),
        ]);

        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9]);
        $contact = WaContact::create(['channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-ar']);
        $path = "wa-media/app/{$contact->id}/note.webm";
        Storage::disk('public')->put($path, 'FAKE-AUDIO');
        $inbound = $contact->recordMessage('in', '🎤 …', 'audio', null, null, ['media_path' => $path, 'media_mime' => 'audio/webm']);

        dispatch_sync(new ProcessWaReply($inbound->id));

        // Inbound now carries the transcript.
        $this->assertSame('🎤 كم سعر القص؟', $inbound->fresh()->body);

        // Reply is an audio message with stored, playable audio + the text caption.
        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertSame('audio', $out->type);
        $this->assertStringStartsWith('🔊 ', $out->body);
        $this->assertNotNull($out->media_path);
        Storage::disk('public')->assertExists($out->media_path);

        // No WhatsApp Graph calls for the app channel.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_unintelligible_voice_falls_back_to_text_prompt(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => '']), // nothing heard
        ]);

        $shop = Shop::factory()->create();
        $contact = WaContact::create(['channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-empty']);
        $path = "wa-media/app/{$contact->id}/note.webm";
        Storage::disk('public')->put($path, 'FAKE');
        $inbound = $contact->recordMessage('in', '🎤 …', 'audio', null, null, ['media_path' => $path, 'media_mime' => 'audio/webm']);

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString("couldn't open that", $out->body);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic'));
    }
}
