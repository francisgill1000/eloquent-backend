<?php
namespace App\Services\Assistant\Contracts;

use App\Services\Assistant\Support\ToolCall;

interface AssistantToolModule
{
    /** @return array<int, array<string, mixed>> Anthropic tool schemas. */
    public function toolDefs(): array;

    public function handles(string $tool): bool;

    /** @return array<string, mixed> */
    public function run(ToolCall $call): array;

    /** Product module this tool belongs to: null = universal, else 'bookings'|'leads'. */
    public function moduleKey(): ?string;
}
