<?php

namespace Tests\Unit\Avatar;

use App\Services\Avatar\LiveAvatarClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveAvatarClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.liveavatar.api_key'      => 'la-test',
            'services.liveavatar.base_url'     => 'https://api.liveavatar.com',
            'services.liveavatar.llm_config_id' => 'llm-cfg-1',
        ]);
    }

    public function test_create_session_sends_avatar_voice_and_llm_config(): void
    {
        Http::fake([
            '*/v1/sessions/token' => Http::response(['token' => 'sess-tok'], 200),
            '*/v1/sessions/start' => Http::response(['session_id' => 'sid', 'livekit' => ['url' => 'wss://x']], 200),
        ]);

        $out = app(LiveAvatarClient::class)->createSession([
            'avatar_id'     => 'av_1',
            'voice_id'      => 'vo_1',
            'system_prompt' => 'be nice',
        ]);

        $this->assertSame('sid', $out['session_id']);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/v1/sessions/start')
                && $req['avatar_id'] === 'av_1'
                && $req['voice_id'] === 'vo_1'
                && $req['llm_configuration_id'] === 'llm-cfg-1'
                && str_contains($req['system_prompt'], 'be nice');
        });
    }

    public function test_missing_api_key_throws(): void
    {
        config(['services.liveavatar.api_key' => null]);
        $this->expectException(\RuntimeException::class);
        app(LiveAvatarClient::class)->createSession(['avatar_id' => 'a', 'voice_id' => 'v', 'system_prompt' => 'x']);
    }
}
