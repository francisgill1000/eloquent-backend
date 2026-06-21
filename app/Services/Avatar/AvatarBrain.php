<?php

namespace App\Services\Avatar;

use App\Models\Shop;
use App\Services\Wa\ClaudeClient;
use App\Services\Wa\PersonaResolver;
use InvalidArgumentException;

/**
 * Read-only bridge between LiveAvatar's custom-LLM call and the existing Rezzy
 * brain. It extracts the signed session token from the incoming system message,
 * rebuilds the authoritative system prompt server-side via PersonaResolver, and
 * returns the brain's reply text. It never persists chat messages or sends any
 * outbound message — those side effects live only in ProcessWaReply.
 */
class AvatarBrain
{
    public function __construct(
        private ClaudeClient $claude,
        private PersonaResolver $persona,
    ) {}

    /** @param array<int, array{role?: string, content?: mixed}> $messages OpenAI-format messages */
    public function answer(array $messages): string
    {
        $systemText = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $systemText = is_string($m['content'] ?? null) ? $m['content'] : '';
                break;
            }
        }

        $token = AvatarSessionToken::extractFromText($systemText);
        if ($token === null) {
            throw new InvalidArgumentException('Missing avatar session token.');
        }

        $ctx = AvatarSessionToken::verify($token); // throws on tamper
        $shop = Shop::findOrFail($ctx['shop_id']);

        $history = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                continue;
            }
            $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $history[] = ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
        }

        $prompt = $this->persona->systemPrompt($shop);

        return $this->claude->reply($prompt, $history);
    }
}
