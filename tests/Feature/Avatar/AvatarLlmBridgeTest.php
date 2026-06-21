<?php

namespace Tests\Feature\Avatar;

use App\Models\Shop;
use App\Models\WaContact;
use App\Services\Avatar\AvatarSessionToken;
use App\Services\Wa\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AvatarLlmBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.liveavatar.session_secret' => 'sek',
            'services.anthropic.key'             => 'sk-test',
        ]);
    }

    public function test_bridge_streams_brain_reply_as_openai_sse(): void
    {
        $shop = Shop::factory()->create();
        $token = AvatarSessionToken::issue($shop->id, 'dev-1');

        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('toolLoop')->once()->andReturn('We open at 9am.');
        $this->app->instance(ClaudeClient::class, $claude);

        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => "ctx\n" . sprintf(AvatarSessionToken::MARKER, $token)],
                ['role' => 'user', 'content' => 'what time do you open?'],
            ],
            'stream' => true,
        ];

        $res = $this->postJson('/api/avatar/llm/chat/completions', $payload);
        $res->assertOk();
        $body = $res->streamedContent();
        $this->assertStringContainsString('"delta"', $body);
        $this->assertStringContainsString('We open at 9am.', $body);
        $this->assertStringContainsString('data: [DONE]', $body);
    }

    public function test_booking_uses_isolated_avatar_channel_contact(): void
    {
        $shop = Shop::factory()->create();
        $token = AvatarSessionToken::issue($shop->id, 'dev-7');

        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('toolLoop')->once()->andReturn('Booked!');
        $this->app->instance(ClaudeClient::class, $claude);

        $this->postJson('/api/avatar/llm/chat/completions', [
            'messages' => [
                ['role' => 'system', 'content' => sprintf(AvatarSessionToken::MARKER, $token)],
                ['role' => 'user', 'content' => 'book me 3pm tomorrow'],
            ],
        ])->assertOk();

        $contact = WaContact::where('shop_id', $shop->id)
            ->where('device_id', 'dev-7')->where('channel', 'avatar')->first();
        $this->assertNotNull($contact);
        // must NOT have created an 'app' (live-chat) contact
        $this->assertNull(WaContact::where('channel', 'app')->first());
    }

    public function test_bridge_rejects_request_without_valid_token(): void
    {
        $res = $this->postJson('/api/avatar/llm/chat/completions', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]);
        $res->assertStatus(400);
    }
}
