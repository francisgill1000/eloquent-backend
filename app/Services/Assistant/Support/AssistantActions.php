<?php
namespace App\Services\Assistant\Support;

use App\Models\AssistantPendingAction;

/**
 * Request-scoped sink for UI directives a tool wants to hand back to the chat
 * client: a navigation, or a pending change awaiting the owner's tap. A tool
 * records intent here; the owner assistant controller reads it after the tool
 * loop and attaches it to the reply. Bound as a singleton so the tool and the
 * controller share one instance.
 */
class AssistantActions
{
    private ?array $action = null;

    private ?int $conversationId = null;

    public function navigate(string $route): void
    {
        $this->action = ['type' => 'navigate', 'route' => $route];
    }

    /**
     * Hand the client a change to confirm: what it will do, in the owner's
     * words. The tool's arguments are never sent, and the id is the only thing
     * that comes back — the server re-executes from the row it stored, so the
     * client cannot influence what gets written.
     */
    public function confirm(AssistantPendingAction $row): void
    {
        $this->action = [
            'type' => 'confirm',
            'id' => $row->id,
            'summary' => $row->summary,
            'changes' => $row->changes,
            'destructive' => $row->destructive,
        ];
    }

    /**
     * The thread this turn belongs to, so a pending row can be tied to it. Null
     * on the first turn of a new chat — the controller backfills it once the
     * conversation exists.
     */
    public function forConversation(?int $id): void
    {
        $this->conversationId = $id;
    }

    public function conversationId(): ?int
    {
        return $this->conversationId;
    }

    /** @return array<string, mixed>|null */
    public function pending(): ?array
    {
        return $this->action;
    }
}
