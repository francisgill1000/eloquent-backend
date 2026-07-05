<?php

namespace App\Services\Leads\Sources;

use App\Services\Leads\Contracts\LeadSourceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Places (legacy) source: Text Search for candidates, then Place Details
 * per place for phone + website. Uses a SERVER-side, IP-restricted key from
 * config('services.google_places.key') — separate from the frontend Maps key,
 * and never logged or returned to the client.
 *
 * All HTTP is timed out + retried and wrapped so a provider outage degrades to
 * an empty result set rather than a 500.
 */
class GooglePlacesSource implements LeadSourceInterface
{
    private const TEXT_SEARCH = 'https://maps.googleapis.com/maps/api/place/textsearch/json';
    private const DETAILS = 'https://maps.googleapis.com/maps/api/place/details/json';

    /** Cap details lookups to the first page to protect API spend. */
    private const MAX_RESULTS = 20;

    public function key(): string
    {
        return 'google_places';
    }

    public function search(string $query, ?string $area): array
    {
        $apiKey = config('services.google_places.key');
        if (! $apiKey) {
            Log::warning('Lead search skipped: google_places.key is not configured.');
            return [];
        }

        $text = trim($area ? "{$query} in {$area}" : $query);

        try {
            $resp = Http::timeout(8)
                ->retry(2, 200, throw: false)
                ->get(self::TEXT_SEARCH, [
                    'query' => $text,
                    'region' => 'ae',
                    'key' => $apiKey,
                ]);

            if (! $resp->successful()) {
                Log::warning('Lead text search failed', ['status' => $resp->status()]);
                return [];
            }

            $status = $resp->json('status');
            if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
                // Never include the key; log only Google's status field.
                Log::warning('Lead text search returned non-OK', ['google_status' => $status]);
                return [];
            }

            $places = array_slice($resp->json('results', []), 0, self::MAX_RESULTS);

            $out = [];
            foreach ($places as $p) {
                $out[] = $this->hydratePlace($p, $apiKey);
            }
            return $out;
        } catch (\Throwable $e) {
            // Message may echo the URL (with key) — log class only, not message.
            Log::warning('Lead search errored', ['exception' => get_class($e)]);
            return [];
        }
    }

    /**
     * Merge Text Search fields with a Place Details lookup for phone/website.
     */
    private function hydratePlace(array $p, string $apiKey): array
    {
        $ref = $p['place_id'] ?? null;
        $phone = null;
        $website = null;

        if ($ref) {
            $details = $this->fetchDetails($ref, $apiKey);
            $phone = $details['international_phone_number']
                ?? $details['formatted_phone_number']
                ?? null;
            $website = $details['website'] ?? null;
        }

        return [
            'name' => $p['name'] ?? 'Unknown',
            'phone' => $phone,
            'website' => $website,
            'address' => $p['formatted_address'] ?? null,
            'category' => $p['types'][0] ?? null,
            'lat' => $p['geometry']['location']['lat'] ?? null,
            'lng' => $p['geometry']['location']['lng'] ?? null,
            'rating' => isset($p['rating']) ? (float) $p['rating'] : null,
            'external_ref' => (string) $ref,
            'source' => $this->key(),
        ];
    }

    /** @return array<string, mixed> Empty on any failure. */
    private function fetchDetails(string $placeId, string $apiKey): array
    {
        try {
            $resp = Http::timeout(8)
                ->retry(2, 200, throw: false)
                ->get(self::DETAILS, [
                    'place_id' => $placeId,
                    'fields' => 'international_phone_number,formatted_phone_number,website',
                    'key' => $apiKey,
                ]);

            if (! $resp->successful() || $resp->json('status') !== 'OK') {
                return [];
            }
            return $resp->json('result', []);
        } catch (\Throwable $e) {
            Log::warning('Lead details errored', ['exception' => get_class($e)]);
            return [];
        }
    }
}
