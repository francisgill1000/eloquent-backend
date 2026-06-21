<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TtsControllerTest extends TestCase
{
    public function test_requires_text(): void
    {
        config(['services.elevenlabs.api_key' => 'k']);

        $this->postJson('/api/tts', ['text' => ''])->assertStatus(422);
    }

    public function test_returns_503_when_not_configured(): void
    {
        config(['services.elevenlabs.api_key' => null]);

        $this->postJson('/api/tts', ['text' => 'hello'])->assertStatus(503);
    }

    public function test_streams_audio_from_elevenlabs(): void
    {
        config([
            'services.elevenlabs.api_key'  => 'k',
            'services.elevenlabs.voice_id' => 'VOICE',
            'services.elevenlabs.model_id' => 'eleven_turbo_v2_5',
        ]);
        Cache::flush();
        Http::fake([
            'api.elevenlabs.io/*' => Http::response('AUDIOBYTES', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $res = $this->postJson('/api/tts', ['text' => 'We open at 9am tomorrow.']);

        $res->assertOk();
        $this->assertSame('audio/mpeg', $res->headers->get('Content-Type'));
        $this->assertSame('AUDIOBYTES', $res->getContent());
        Http::assertSent(fn ($req) => str_contains($req->url(), '/text-to-speech/VOICE')
            && $req['text'] === 'We open at 9am tomorrow.');
    }

    public function test_caches_repeated_text(): void
    {
        config(['services.elevenlabs.api_key' => 'k', 'services.elevenlabs.voice_id' => 'VOICE']);
        Cache::flush();
        Http::fake(['api.elevenlabs.io/*' => Http::response('BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->postJson('/api/tts', ['text' => 'same text'])->assertOk();
        $this->postJson('/api/tts', ['text' => 'same text'])->assertOk();

        // Second request served from cache — ElevenLabs hit only once.
        Http::assertSentCount(1);
    }
}
