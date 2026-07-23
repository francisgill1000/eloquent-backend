<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Scopes\AssignedLeadScope;
use App\Models\Shop;
use App\Support\Rbac;

/**
 * Persists discovered businesses as leads, deduping on (shop_id, external_ref)
 * so re-saving the same business updates rather than clones. Shared by
 * LeadController::store (bulk save from the UI) and the Hunt assistant's
 * save_leads tool, so both paths dedupe identically.
 */
class LeadImporter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  string|null  $pipeline  Named pipeline/list to file these leads under.
     * @return array{saved: array<int, Lead>, created: int}
     */
    public function import(Shop $shop, array $rows, ?string $pipeline = null): array
    {
        $saved = [];
        $created = 0;
        $pipeline = $pipeline !== null ? trim($pipeline) : null;
        $pipeline = $pipeline === '' ? null : $pipeline;

        // An agent who cannot see the whole shop must own what they save —
        // otherwise the lead vanishes from their screen the instant it is
        // created. Takes priority over round-robin (you keep what you find).
        $actor = current_shop_user();
        $selfAssign = $actor !== null && ! Rbac::seesAllLeads($actor);

        foreach ($rows as $row) {
            $attrs = [
                'name' => $row['name'],
                'phone' => $row['phone'] ?? null,
                'whatsapp' => $row['whatsapp'] ?? ($row['phone'] ?? null),
                'website' => $row['website'] ?? null,
                'address' => $row['address'] ?? null,
                'category' => $row['category'] ?? null,
                'lat' => $row['lat'] ?? null,
                'lng' => $row['lng'] ?? null,
                'source' => $row['source'] ?? 'manual',
            ];

            // Only stamp the pipeline when one was supplied — the Hunt assistant's
            // save_leads path passes none, so its leads stay unfiled.
            if ($pipeline !== null) {
                $attrs['pipeline'] = $pipeline;
            }

            if (! empty($row['external_ref'])) {
                // Scope-free: an agent re-saving a business owned by a colleague
                // must find and update that row, not insert a duplicate that
                // violates unique(shop_id, external_ref) and then fail recovery.
                $lead = Lead::withoutGlobalScope(AssignedLeadScope::class)->firstOrNew([
                    'shop_id' => $shop->id,
                    'external_ref' => $row['external_ref'],
                ]);
                $lead = $this->saveDeduped($lead, $attrs, $shop->id, $row['external_ref']);
            } else {
                $lead = Lead::create($attrs + [
                    'shop_id' => $shop->id,
                    'status' => 'new',
                ]);
            }

            if ($lead->wasRecentlyCreated) {
                $created++;

                if ($selfAssign && $lead->assigned_to_id === null) {
                    $lead->assigned_to_id = $actor->id;
                    $lead->assigned_at = now();
                    $lead->save();
                }
            }
            $saved[] = $lead;
        }

        return ['saved' => $saved, 'created' => $created];
    }

    /**
     * Save a firstOrNew()'d lead, recovering from the case where a concurrent
     * import already inserted the same (shop_id, external_ref) row after our
     * SELECT ran but before our INSERT did — that would otherwise surface as
     * an uncaught duplicate-key 500 instead of just updating the existing row.
     */
    public function saveDeduped(Lead $lead, array $attrs, int $shopId, string $externalRef): Lead
    {
        $lead->fill($attrs);
        if (! $lead->exists) {
            $lead->status = 'new';
        }

        try {
            $lead->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $lead = Lead::withoutGlobalScope(AssignedLeadScope::class)
                ->where('shop_id', $shopId)->where('external_ref', $externalRef)->firstOrFail();
            $lead->fill($attrs);
            $lead->save();
        }

        return $lead;
    }
}
