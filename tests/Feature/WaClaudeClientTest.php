<?php

namespace Tests\Feature;

use App\Services\Wa\ClaudeClient;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_tool_loop_feeds_results_back_until_text(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push(['content' => [
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_time', 'input' => []],
                ]])
                ->push(['content' => [['type' => 'text', 'text' => 'It is 3pm.']]]),
        ]);

        $calls = [];
        $reply = (new ClaudeClient())->toolLoop(
            'You are a bot.',
            [['role' => 'user', 'content' => 'what time is it?']],
            [['name' => 'get_time', 'input_schema' => ['type' => 'object', 'properties' => (object) []]]],
            function (string $name, array $input) use (&$calls) {
                $calls[] = $name;
                return json_encode(['time' => '15:00']);
            },
        );

        $this->assertSame('It is 3pm.', $reply);
        $this->assertSame(['get_time'], $calls);

        // The second request must echo the tool_use with input as an OBJECT
        // ({}), not a JSON array ([]) — the empty-input encoding bug.
        Http::assertSent(function ($request) {
            if (!is_array($request['messages'][1]['content'] ?? null)) {
                return false; // the first request (plain history) — ignore
            }
            $assistant = $request['messages'][1]['content'][0] ?? [];
            if (($assistant['type'] ?? null) !== 'tool_use') {
                return false;
            }
            // Re-encode just this block and confirm the empty input is "{}".
            return str_contains(json_encode($assistant), '"input":{}');
        });
    }

    public function test_throws_on_api_error(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->expectException(\RuntimeException::class);

        (new ClaudeClient())->reply('sys', [['role' => 'user', 'content' => 'hi']]);
    }

    public function test_retries_transient_connection_reset_then_succeeds(): void
    {
        config(['services.anthropic.key' => 'sk-test']);

        // First call drops the connection (cURL 35 reset); the retry succeeds.
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('cURL error 35: Connection was reset');
            }
            return Http::response(['content' => [['type' => 'text', 'text' => 'Recovered!']]]);
        });

        $reply = (new ClaudeClient())->reply('sys', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Recovered!', $reply);
        $this->assertSame(2, $attempts); // one failure + one successful retry
    }
}
