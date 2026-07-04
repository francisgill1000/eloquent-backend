<?php
namespace App\Services\Assistant\Support;

use App\Services\Assistant\Contracts\AssistantToolModule;
use App\Support\Rbac;

/**
 * Shared base for every assistant tool module. Owns the RBAC gate and the
 * standard "ambiguous"/"not found" response shapes so every tool answers the
 * model consistently. Subclasses declare a tool=>permission map and a handler.
 */
abstract class AssistantModule implements AssistantToolModule
{
    /** @return array<string, string> toolName => required permission */
    abstract protected function permissions(): array;

    /** Handle a tool this module owns (RBAC already checked). */
    abstract protected function handle(ToolCall $call): array;

    public function handles(string $tool): bool
    {
        return array_key_exists($tool, $this->permissions());
    }

    public function run(ToolCall $call): array
    {
        $perm = $this->permissions()[$call->tool] ?? null;
        if ($perm !== null && ! Rbac::userCan($call->actingUser, $perm)) {
            return ['error' => 'no_permission'];
        }
        return $this->handle($call);
    }

    /** @param array<int, mixed> $matches */
    protected function ambiguous(array $matches): array
    {
        return ['ambiguous' => true, 'matches' => $matches];
    }

    protected function notFound(string $what = 'record'): array
    {
        return ['error' => 'not_found', 'what' => $what];
    }
}
