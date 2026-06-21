<?php

namespace Tests\Feature\Avatar;

use App\Models\Shop;
use App\Services\Avatar\LiveAvatarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AvatarSessionEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.liveavatar.api_key'           => 'la-test',
            'services.liveavatar.llm_config_id'      => 'cfg',
            'services.liveavatar.session_secret'     => 'sek',
            'services.liveavatar.default_avatar_id'  => 'av_default',
            'services.liveavatar.default_voice_id'   => 'vo_default',
            'services.liveavatar.default_context_id' => 'ctx_default',
            'services.anthropic.key'                 => 'sk-test',
        ]);
    }

    public function test_session_endpoint_returns_creds_and_uses_shop_avatar(): void
    {
        $shop = Shop::factory()->create(['avatar_id' => 'av_shop', 'voice_id' => 'vo_shop']);

        $mock = Mockery::mock(LiveAvatarClient::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturnUsing(function (array $opts) {
                $this->assertSame('av_shop', $opts['avatar_id']);
                $this->assertSame('vo_shop', $opts['voice_id']);
                $this->assertSame('ctx_default', $opts['context_id']);
                // session_token is the signed shop+device token (opaque, non-empty)
                $this->assertNotEmpty($opts['session_token']);
                return ['session_id' => 'sid', 'session_token' => 'sess-tok'];
            });
        $this->app->instance(LiveAvatarClient::class, $mock);

        $res = $this->withHeader('X-Device-Id', 'dev-1')
            ->postJson("/api/avatar/shops/{$shop->id}/session");

        $res->assertOk()->assertJsonPath('session_id', 'sid');
    }

    public function test_session_endpoint_falls_back_to_default_avatar(): void
    {
        $shop = Shop::factory()->create(['avatar_id' => null, 'voice_id' => null]);

        $mock = Mockery::mock(LiveAvatarClient::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturnUsing(function (array $opts) {
                $this->assertSame('av_default', $opts['avatar_id']);
                $this->assertSame('vo_default', $opts['voice_id']);
                $this->assertSame('ctx_default', $opts['context_id']);
                return ['session_id' => 'sid2', 'session_token' => 'sess-tok2'];
            });
        $this->app->instance(LiveAvatarClient::class, $mock);

        $this->withHeader('X-Device-Id', 'dev-2')
            ->postJson("/api/avatar/shops/{$shop->id}/session")
            ->assertOk()->assertJsonPath('session_id', 'sid2');
    }

    public function test_missing_device_id_is_rejected(): void
    {
        $shop = Shop::factory()->create();
        $this->postJson("/api/avatar/shops/{$shop->id}/session")->assertStatus(422);
    }
}
