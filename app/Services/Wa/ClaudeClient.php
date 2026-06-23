<?php

namespace App\Services\Wa;

use Illuminate\Support\Facades\Http;

/**
 * Thin Anthropic Messages API client (no SDK). The system prompt is cached
 * with cache_control: ephemeral to cut cost/latency across turns.
 * Ported from whatsapp-autoreply/lib/claude.js.
 */
class ClaudeClient
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    /** @param array<int, array{role: string, content: string}> $history */
    public function reply(string $system, array $history): string
    {
        return $this->text($this->request($system, $history));
    }

    /**
     * Reply with tools enabled (e.g. in-chat account creation).
     *
     * @return array{text: string, toolUse: array{name: string, input: array}|null}
     */
    public function agentReply(string $system, array $history, array $tools): array
    {
        $res = $this->request($system, $history, $tools);

        $toolBlock = collect($res['content'] ?? [])->firstWhere('type', 'tool_use');

        return [
            'text' => $this->text($res),
            'toolUse' => $toolBlock
                ? ['name' => $toolBlock['name'], 'input' => (array) ($toolBlock['input'] ?? [])]
                : null,
        ];
    }

    /**
     * Full agentic loop: the model may call tools several times; each result
     * is fed back until it produces a normal text reply. $execute receives
     * (toolName, input array) and must return a string (JSON) result.
     *
     * @param array<int, array{role: string, content: mixed}> $history
     */
    public function toolLoop(string $system, array $history, array $tools, callable $execute, int $maxTurns = 5): string
    {
        $messages = $history;

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $res = $this->request($system, $messages, $tools);

            $toolBlocks = collect($res['content'] ?? [])->where('type', 'tool_use')->values();
            if ($toolBlocks->isEmpty()) {
                return $this->text($res);
            }

            // Echo the assistant turn back verbatim, but force each tool_use
            // input to a JSON object: an empty input arrives as {} → PHP [] →
            // would re-encode as [] (array), which the API rejects.
            $assistantContent = array_map(function ($block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $block['input'] = (object) ($block['input'] ?? []);
                }
                return $block;
            }, $res['content']);

            $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
            $messages[] = [
                'role' => 'user',
                'content' => $toolBlocks->map(fn ($t) => [
                    'type' => 'tool_result',
                    'tool_use_id' => $t['id'],
                    'content' => $execute($t['name'], (array) ($t['input'] ?? [])),
                ])->all(),
            ];
        }

        // Loop budget exhausted mid-tool-call — let the caller fall back.
        return '';
    }

    /**
     * One raw Anthropic turn — full decoded response (text + tool_use blocks
     * with ids). The assistant agent drives its own loop over this.
     *
     * @param array<int, array{role: string, content: mixed}> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    public function raw(string $system, array $messages, array $tools = []): array
    {
        return $this->request($system, $messages, $tools);
    }

    private function request(string $system, array $history, array $tools = []): array
    {
        $payload = [
            'model' => config('services.anthropic.model', 'claude-haiku-4-5'),
            'max_tokens' => 1024,
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => $history,
        ];
        if ($tools) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->timeout(60)
            ->post(self::URL, $payload);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Claude request failed ({$response->status()}): " . mb_substr($response->body(), 0, 200)
            );
        }

        return $response->json() ?? [];
    }

    private function text(array $res): string
    {
        return trim(collect($res['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode(''));
    }
}
