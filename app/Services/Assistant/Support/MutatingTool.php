<?php
namespace App\Services\Assistant\Support;

/**
 * Base for every data-changing tool. Enforces the confirm-everything gate:
 * a tool writes NOTHING unless the model re-calls it with confirmed=true.
 * The first (unconfirmed) call resolves the real target and returns a preview
 * the assistant reads back. Being a MutatingTool also marks the module for the
 * assistant.mutations_enabled kill-switch in the registry.
 */
abstract class MutatingTool extends AssistantModule
{
    /**
     * @param callable():array $resolve  target record, or notFound()/ambiguous() to short-circuit
     * @param callable(array):array $describe  target => [string $action, array $changes]
     * @param callable(array):array $write  performs the change, returns extra result data
     */
    protected function gate(ToolCall $call, callable $resolve, callable $describe, callable $write): array
    {
        $target = $resolve();

        // resolve() may hand back a terminal response — pass it straight through.
        if (isset($target['error']) || isset($target['ambiguous'])) {
            return $target;
        }

        if (! $call->confirmed) {
            [$action, $changes] = $describe($target);
            return $this->preview($action, $changes);
        }

        return $this->applied($write($target));
    }

    /** @param array<string, mixed> $changes */
    protected function preview(string $action, array $changes = []): array
    {
        return ['preview' => true, 'action' => $action, 'changes' => $changes];
    }

    /** @param array<string, mixed> $data */
    protected function applied(array $data = []): array
    {
        return array_merge(['done' => true], $data);
    }
}
