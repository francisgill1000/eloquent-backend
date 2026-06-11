<?php

namespace Tests\Feature;

use App\Services\Wa\ClaudeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaClaudeClientTest extends TestCase
{
    public function test_reply_returns_joined_text_and_caches_system_prompt(): void
    {
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Hello '],
                    ['type' => 'text', 'text' => 'there!'],
                ],
            ]),
        ]);

        $reply = (new ClaudeClient())->reply('You are a bot.', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Hello there!', $reply);
        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'sk-test')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['model'] === 'claude-haiku-4-5'
                && $request['max_tokens'] === 1024
                && $request['system'][0]['cache_control'] === ['type' => 'ephemeral']
                && !array_key_exists('tools', $request->data());
        });
    }

    public function test_agent_reply_extracts_tool_use(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Creating your account…'],
                    ['type' => 'tool_use', 'name' => 'create_business_account',
                     'id' => 'tu_1', 'input' => ['business_name' => 'Glow Salon', 'category' => 'Salon']],
                ],
            ]),
        ]);

        $tools = [['name' => 'create_business_account', 'input_schema' => ['type' => 'object']]];
        $result = (new ClaudeClient())->agentReply('You are a bot.', [['role' => 'user', 'content' => 'yes']], $tools);

        $this->assertSame('Creating your account…', $result['text']);
        $this->assertSame('create_business_account', $result['toolUse']['name']);
        $this->assertSame('Glow Salon', $result['toolUse']['input']['business_name']);
        Http::assertSent(fn ($request) => $request['tools'] === $tools);
    }

    public function test_throws_on_api_error(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->expectException(\RuntimeException::class);

        (new ClaudeClient())->reply('sys', [['role' => 'user', 'content' => 'hi']]);
    }
}
