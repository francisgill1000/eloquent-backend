<?php
namespace App\Services\Assistant\Contracts;

use App\Models\ShopUser;
use App\Services\Assistant\Support\ToolCall;

interface AssistantToolModule
{
    /** @return array<int, array<string, mixed>> Anthropic tool schemas. */
    public function toolDefs(): array;

    /**
     * toolDefs() filtered to what this user may actually invoke, so the model is
     * never offered a tool that run() will refuse. A null user (owner/untagged
     * session) sees everything.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleToolDefs(?ShopUser $user): array;

    public function handles(string $tool): bool;

    /** @return array<string, mixed> */
    public function run(ToolCall $call): array;

    /** Product module this tool belongs to: null = universal, else 'bookings'|'leads'. */
    public function moduleKey(): ?string;
}
