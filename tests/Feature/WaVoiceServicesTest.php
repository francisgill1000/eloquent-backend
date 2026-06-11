<?php

namespace Tests\Feature;

use App\Services\Wa\Speech;
use App\Services\Wa\Transcriber;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaVoiceServicesTest extends TestCase
{
    public function test_unavailable_without_api_key(): void
    {
        config(['services.openai.key' => null]);

        $this->assertFalse((new Transcriber())->available());
        $this->assertFalse((new Speech())->available());
    }

    public function test_transcribe_posts_multipart_and_returns_text(): void
    {
        config(['services.openai.key' => 'sk-oai']);
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => ' how much is a haircut '])]);

        $text = (new Transcriber())->transcribe('FAKEAUDIO', 'audio/ogg; codecs=opus');

        $this->assertSame('how much is a haircut', $text);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-oai'));
    }

    public function test_transcribe_returns_null_for_empty_text(): void
    {
        config(['services.openai.key' => 'sk-oai']);
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => '  '])]);

        $this->assertNull((new Transcriber())->transcribe('FAKEAUDIO', 'audio/ogg'));
    }

    public function test_synthesize_returns_audio_bytes(): void
    {
        config(['services.openai.key' => 'sk-oai', 'services.openai.tts_model' => 'gpt-4o-mini-tts', 'services.openai.tts_voice' => 'nova']);
        Http::fake(['api.openai.com/v1/audio/speech' => Http::response('OGGBYTES')]);

        $audio = (new Speech())->synthesize('Your booking is confirmed');

        $this->assertSame('OGGBYTES', $audio);
        Http::assertSent(fn ($request) => $request['response_format'] === 'opus' && $request['voice'] === 'nova');
    }

    public function test_voice_services_throw_on_api_error(): void
    {
        config(['services.openai.key' => 'sk-oai']);
        Http::fake(['api.openai.com/*' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);
        (new Speech())->synthesize('hello');
    }
}
