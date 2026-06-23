<?php

namespace App\Services\Ai;

use App\Services\Wa\ClaudeClient;
use Illuminate\Support\Collection;

/**
 * Runs the customer assistant tool loop. Read tools execute server-side and the
 * loop continues; an action tool (navigate/register/login) stops the loop and
 * returns a directive for the client to execute.
 */
class AssistantAgent
{
    /** Routes the model is allowed to navigate to. /shop/{id} is matched separately. */
    private const STATIC_ROUTES = ['/', '/explore', '/near-me', '/ai', '/favourites', '/bookings', '/account', '/login', '/register'];

    public function __construct(private ClaudeClient $claude) {}

    /**
     * @param array<int, array{role: string, content: mixed}> $messages
     * @return array{reply: string, action: ?array, shops: Collection}
     */
    public function run(string $system, array $messages, AssistantTools $tools, int $maxTurns = 5): array
    {
        $defs = AssistantTools::defs();

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $res = $this->claude->raw($system, $messages, $defs);
            $content = $res['content'] ?? [];

            $text = trim(collect($content)->where('type', 'text')->pluck('text')->implode(''));
            $toolBlocks = collect($content)->where('type', 'tool_use')->values();

            if ($toolBlocks->isEmpty()) {
                return ['reply' => $text, 'action' => null, 'shops' => $tools->collectedShops()];
            }

            // An action tool ends the turn with a client directive.
            $action = $toolBlocks
                ->map(fn ($b) => $this->actionFor($b['name'], (array) ($b['input'] ?? [])))
                ->first(fn ($a) => $a !== null);

            if ($action !== null) {
                return ['reply' => $text, 'action' => $action, 'shops' => $tools->collectedShops()];
            }

            // Read tools: echo the assistant turn, append one tool_result each, continue.
            $messages[] = ['role' => 'assistant', 'content' => array_map(function ($b) {
                if (($b['type'] ?? null) === 'tool_use') {
                    $b['input'] = (object) ($b['input'] ?? []);
                }
                return $b;
            }, $content)];

            $messages[] = ['role' => 'user', 'content' => $toolBlocks->map(fn ($t) => [
                'type' => 'tool_result',
                'tool_use_id' => $t['id'],
                'content' => $tools->executeRead($t['name'], (array) ($t['input'] ?? [])),
            ])->all()];
        }

        // Loop exhausted mid-tool-call — return whatever text we have, no action.
        return ['reply' => '', 'action' => null, 'shops' => $tools->collectedShops()];
    }

    /** Build a client directive for an action tool, or null for read tools / invalid input. */
    private function actionFor(string $name, array $input): ?array
    {
        if (!in_array($name, AssistantTools::ACTION_TOOLS, true)) {
            return null;
        }

        if ($name === 'navigate') {
            $route = trim((string) ($input['route'] ?? ''));
            $ok = in_array($route, self::STATIC_ROUTES, true) || preg_match('#^/shop/\d+$#', $route) === 1;
            return $ok ? ['type' => 'navigate', 'route' => $route] : null;
        }

        if ($name === 'register') {
            return ['type' => 'register', 'fields' => array_filter([
                'name' => isset($input['name']) ? (string) $input['name'] : null,
                'phone' => isset($input['phone']) ? (string) $input['phone'] : null,
            ], fn ($v) => $v !== null)];
        }

        // login
        return ['type' => 'login', 'fields' => array_filter([
            'phone' => isset($input['phone']) ? (string) $input['phone'] : null,
        ], fn ($v) => $v !== null)];
    }
}
