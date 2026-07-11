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
     * @return array{saved: array<int, Lead>, created: int}
     */
    public function import(Shop $shop, array $rows): array
    {
        $saved = [];
        $created = 0;

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

            if (! empty($row['external_ref'])) {
                $lead = Lead::firstOrNew([
                    'shop_id' => $shop->id,
                    'external_ref' => $row['external_ref'],
                ]);
                $lead->fill($attrs);
                if (! $lead->exists) {
                    $lead->status = 'new';
                }
                $lead->save();
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
}
