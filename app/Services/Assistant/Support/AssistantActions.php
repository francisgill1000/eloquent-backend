<?php
namespace App\Services\Assistant\Support;

/**
 * Request-scoped sink for UI directives a tool wants to hand back to the chat
 * client (currently just navigation). A tool records intent here; the owner
 * assistant controller reads it after the tool loop and attaches it to the reply.
 * Bound as a singleton so the tool and the controller share one instance.
 */
class AssistantActions
{
    private ?array $action = null;

    public function navigate(string $route): void
    {
        $this->action = ['type' => 'navigate', 'route' => $route];
    }

    /** @return array{type: string, route: string}|null */
    public function pending(): ?array
    {
        return $this->action;
    }
}
