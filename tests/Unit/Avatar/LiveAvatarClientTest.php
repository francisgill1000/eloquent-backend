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
            'services.liveavatar.api_key'       => 'la-test',
            'services.liveavatar.base_url'       => 'https://api.liveavatar.com',
            'services.liveavatar.llm_config_id'  => 'llm-cfg-1',
        ]);
    }

    public function test_create_session_posts_full_mode_token_request(): void
    {
        Http::fake([
            '*/v1/sessions/token' => Http::response([
                'code' => 1000,
                'data' => ['session_id' => 'sid', 'session_token' => 'sess-tok'],
            ], 200),
        ]);

        $out = app(LiveAvatarClient::class)->createSession([
            'avatar_id'     => 'av_1',
            'voice_id'      => 'vo_1',
            'context_id'    => 'ctx_1',
            'session_token' => 'signed-token',
        ]);

        $this->assertSame('sess-tok', $out['session_token']);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/v1/sessions/token')
                && $req->hasHeader('X-API-KEY', 'la-test')
                && $req['mode'] === 'FULL'
                && $req['avatar_id'] === 'av_1'
                && $req['avatar_persona']['voice_id'] === 'vo_1'
                && $req['avatar_persona']['context_id'] === 'ctx_1'
                && $req['llm_configuration_id'] === 'llm-cfg-1'
                && $req['interactivity_type'] === 'CONVERSATIONAL'
                && $req['dynamic_variables']['session'] === 'signed-token';
        });
    }

    public function test_missing_api_key_throws(): void
    {
        config(['services.liveavatar.api_key' => null]);
        $this->expectException(\RuntimeException::class);
        app(LiveAvatarClient::class)->createSession([
            'avatar_id' => 'a', 'voice_id' => 'v', 'context_id' => 'c', 'session_token' => 't',
        ]);
    }
}
