<?php
namespace App\Services\Assistant\Support;

use App\Models\Lead;

/**
 * Shared fuzzy-by-name lead resolution for the Hunt assistant modules. Hosting
 * classes must extend AssistantModule (for notFound()/ambiguous()).
 */
trait ResolvesLeads
{
    /** @return Lead|array a Lead, or a notFound()/ambiguous() response array. */
    protected function resolveLead(ToolCall $call): Lead|array
    {
        $name = trim((string) $call->get('name'));
        if ($name === '') {
            return $this->notFound('lead');
        }

        $matches = Lead::forShop($call->shop->id)
            ->where('name', 'like', "%{$name}%")
            ->orderByDesc('id')->limit(6)->get();

        if ($matches->isEmpty()) {
            return $this->notFound('lead');
        }
        if ($matches->count() > 1) {
            return $this->ambiguous($matches->map(fn ($l) => ['name' => $l->name, 'status' => $l->status])->all());
        }

        return $matches->first();
    }
}
