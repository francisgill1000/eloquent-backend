<?php
namespace App\Services\Assistant\Support;

use App\Models\AssistantPendingAction;

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
     * Tools whose write cannot be triggered by the model — only by the owner
     * tapping Confirm. Override per module.
     *
     * @return array<int, string>
     */
    protected function destructive(): array
    {
        return [];
    }

    /**
     * @param callable():array $resolve  target record, or notFound()/ambiguous() to short-circuit
     * @param callable(array):array $describe  target => [string $action, array $changes]
     * @param callable(array):array $write  performs the change, returns extra result data
     */
    protected function gate(ToolCall $call, callable $resolve, callable $describe, callable $write): array
    {
        $target = $resolve();

        // resolve() may hand back a terminal response (notFound()/ambiguous(),
        // always arrays) — pass it straight through. Guard with is_array first:
        // a resolved record may be a plain stdClass (DB row) which would throw
        // on array access, and Eloquent models need no such check.
        if (is_array($target) && (isset($target['error']) || isset($target['ambiguous']))) {
            return $target;
        }

        // A destructive tool ignores a confirmed flag the model set itself: the
        // model skips the confirm turn ~12% of the time and narrates success
        // anyway, so its say-so is not enough to delete anything.
        $destructive = in_array($call->tool, $this->destructive(), true);
        $mayWrite = $call->confirmed && (! $destructive || $call->userConfirmed);

        if (! $mayWrite) {
            [$action, $changes] = $describe($target);
            $row = AssistantPendingAction::create([
                'shop_id' => $call->shop->id,
                'conversation_id' => app(AssistantActions::class)->conversationId(),
                'tool' => $call->tool,
                'input' => $call->input,
                'summary' => $action,
                'changes' => $changes,
                'destructive' => $destructive,
                'expires_at' => now()->addMinutes(30),
            ]);
            app(AssistantActions::class)->confirm($row);
            return $this->preview($action, $changes, $destructive);
        }

        $result = $this->applied($write($target));

        // The model confirmed this itself, so any card the client is still
        // showing for the same tool is spent — resolve it or a tap would
        // write a second time.
        AssistantPendingAction::open($call->shop->id, $call->tool)->update(['resolved_at' => now()]);

        return $result;
    }

    /**
     * A preview result. It must be impossible for the model to mistake this for
     * success: `saved => false` plus an explicit `next` instruction keep the
     * model from announcing a done change or inventing a reference number.
     *
     * A destructive tool gets a DIFFERENT instruction. It can only ever be
     * written by the owner tapping Confirm, so telling the model to re-call it
     * with confirmed=true would just return this same preview — the model
     * obeys, loops, and burns the tool-loop budget until the turn dead-ends
     * with no card at all. Tell it to read the change back and stop instead.
     *
     * @param array<string, mixed> $changes
     */
    protected function preview(string $action, array $changes = [], bool $destructive = false): array
    {
        return [
            'preview' => true,
            'saved' => false,
            'action' => $action,
            'changes' => $changes,
            'next' => $destructive
                ? 'NOT SAVED. Nothing has changed yet. The owner has been shown a Confirm button for this in the app and will tap it themselves — you cannot make this change and calling this tool again will do nothing. Do NOT call this tool again. Just read the change back in one short sentence, tell the owner to confirm it in the app, and stop. Do NOT say it is done and do NOT state any reference number.'
                : 'NOT SAVED. Nothing has changed yet. Read this back to the owner and ask them to confirm. Only if they clearly agree, call this SAME tool again with confirmed=true. Do NOT tell the owner it is done, and do NOT state any reference number, until you receive a result with done=true.',
        ];
    }

    /** @param array<string, mixed> $data */
    protected function applied(array $data = []): array
    {
        return array_merge(['done' => true, 'saved' => true], $data);
    }
}
