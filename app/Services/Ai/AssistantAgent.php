<?php

namespace App\Services\Ai;

use App\Models\Shop;
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

            // Build a tool_result per block. A VALID action tool short-circuits the
            // loop; an INVALID one (e.g. navigate to a shop that doesn't exist) and
            // every read tool feed a result back so the model can recover/continue.
            $toolResults = [];
            foreach ($toolBlocks as $t) {
                $name = $t['name'];
                $input = (array) ($t['input'] ?? []);

                if (in_array($name, AssistantTools::ACTION_TOOLS, true)) {
                    $error = $this->actionError($name, $input);
                    if ($error === null) {
                        return ['reply' => $text, 'action' => $this->actionFor($name, $input), 'shops' => $tools->collectedShops()];
                    }
                    $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $t['id'], 'content' => $error];
                    continue;
                }

                $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $t['id'], 'content' => $tools->executeRead($name, $input)];
            }

            // Echo the assistant turn, append the tool_results, continue.
            $messages[] = ['role' => 'assistant', 'content' => array_map(function ($b) {
                if (($b['type'] ?? null) === 'tool_use') {
                    $b['input'] = (object) ($b['input'] ?? []);
                }
                return $b;
            }, $content)];

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        // Loop exhausted mid-tool-call — return whatever text we have, no action.
        return ['reply' => '', 'action' => null, 'shops' => $tools->collectedShops()];
    }

    /**
     * Validate an action tool call. Returns null when it's actionable (the loop
     * may short-circuit), or a JSON error string to feed back so the model can
     * recover — e.g. it guessed a shop id that doesn't exist.
     */
    private function actionError(string $name, array $input): ?string
    {
        if ($name !== 'navigate') {
            return null; // register / login are always actionable
        }

        $route = trim((string) ($input['route'] ?? ''));
        if (in_array($route, self::STATIC_ROUTES, true)) {
            return null;
        }
        if (preg_match('#^/shop/(\d+)$#', $route, $m)) {
            $exists = Shop::where('status', Shop::ACTIVE)->whereKey((int) $m[1])->exists();
            return $exists ? null : json_encode([
                'error' => "No shop with id {$m[1]} exists. Call search_shops to find the shop the user means and navigate using its real id — do not guess ids.",
            ]);
        }

        return json_encode([
            'error' => "Route '{$route}' is not allowed. Use one of: /, /explore, /near-me, /ai, /favourites, /bookings, /account, /login, /register, or /shop/{id} for a shop that exists.",
        ]);
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
