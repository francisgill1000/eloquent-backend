<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TtsControllerTest extends TestCase
{
    public function test_requires_text(): void
    {
        config(['services.openai.key' => 'k']);

        $this->postJson('/api/tts', ['text' => ''])->assertStatus(422);
    }

    public function test_returns_503_when_not_configured(): void
    {
        config(['services.openai.key' => null]);

        $this->postJson('/api/tts', ['text' => 'hello'])->assertStatus(503);
    }

    public function test_streams_audio_from_openai(): void
    {
        config([
            'services.openai.key'       => 'k',
            'services.openai.tts_model' => 'gpt-4o-mini-tts',
            'services.openai.tts_voice' => 'nova',
        ]);
        Cache::flush();
        Http::fake([
            'api.openai.com/*' => Http::response('AUDIOBYTES', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $res = $this->postJson('/api/tts', ['text' => 'We open at 9am tomorrow.']);

        $res->assertOk();
        $this->assertSame('audio/mpeg', $res->headers->get('Content-Type'));
        $this->assertSame('AUDIOBYTES', $res->getContent());
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/audio/speech')
            && $req['voice'] === 'nova'
            && $req['response_format'] === 'mp3'
            && $req['input'] === 'We open at 9am tomorrow.');
    }

    public function test_caches_repeated_text(): void
    {
        config(['services.openai.key' => 'k']);
        Cache::flush();
        Http::fake(['api.openai.com/*' => Http::response('BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->postJson('/api/tts', ['text' => 'same text'])->assertOk();
        $this->postJson('/api/tts', ['text' => 'same text'])->assertOk();

        // Second request served from cache — OpenAI hit only once.
        Http::assertSentCount(1);
    }

    public function test_uses_whitelisted_voice_from_request(): void
    {
        config(['services.openai.key' => 'k', 'services.openai.tts_voice' => 'nova']);
        \Illuminate\Support\Facades\Cache::flush();
        Http::fake(['api.openai.com/*' => Http::response('BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->postJson('/api/tts', ['text' => 'hello there', 'voice' => 'shimmer'])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/audio/speech') && $req['voice'] === 'shimmer');
    }

    public function test_ignores_unknown_voice_and_uses_default(): void
    {
        config(['services.openai.key' => 'k', 'services.openai.tts_voice' => 'nova']);
        \Illuminate\Support\Facades\Cache::flush();
        Http::fake(['api.openai.com/*' => Http::response('BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->postJson('/api/tts', ['text' => 'hello there', 'voice' => 'bogus'])->assertOk();

        Http::assertSent(fn ($req) => $req['voice'] === 'nova');
    }
}
