<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Shop;

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
                $lead = Lead::firstOrNew([
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
            $lead = Lead::where('shop_id', $shopId)->where('external_ref', $externalRef)->firstOrFail();
            $lead->fill($attrs);
            $lead->save();
        }

        return $lead;
    }
}
