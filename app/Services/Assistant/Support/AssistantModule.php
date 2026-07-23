<?php
namespace App\Services\Assistant\Support;

use App\Models\ShopUser;
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

    /**
     * Hide tool schemas the acting user can't invoke. Without this the model
     * proposes e.g. search_businesses to a leads.view-only user and the
     * conversation dead-ends on run()'s no_permission.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleToolDefs(?ShopUser $user): array
    {
        $perms = $this->permissions();

        return array_values(array_filter($this->toolDefs(), function (array $def) use ($perms, $user) {
            $perm = $perms[$def['name'] ?? ''] ?? null;

            return $perm === null || Rbac::userCan($user, $perm);
        }));
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

    public function moduleKey(): ?string
    {
        return null;
    }
}
