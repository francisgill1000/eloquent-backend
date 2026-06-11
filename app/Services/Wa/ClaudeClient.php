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
