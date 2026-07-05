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

    /**
     * Google Text Search returns 20 results/page and caps at ~60 (3 pages) for
     * a single query. We pull all available pages so the shop sees the full set
     * (details are then fetched per place, so this scales API spend with count).
     */
    private const MAX_RESULTS = 60;
    private const MAX_PAGES = 3;

    /** next_page_token needs a short activation delay before Google accepts it. */
    private const PAGE_TOKEN_DELAY_US = 2_000_000;

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
            $places = array_slice($this->textSearchAllPages($text, $apiKey), 0, self::MAX_RESULTS);

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
     * Text Search across all available pages (Google caps at ~60 / 3 pages).
     * The next_page_token from one page becomes valid ~2s later, so we wait
     * before requesting the next. Any page-level failure just stops paging and
     * returns whatever we have so far.
     *
     * @return array<int, array<string, mixed>> Raw Google place results.
     */
    private function textSearchAllPages(string $text, string $apiKey): array
    {
        $all = [];
        $params = ['query' => $text, 'region' => 'ae', 'key' => $apiKey];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $resp = Http::timeout(8)
                ->retry(2, 200, throw: false)
                ->get(self::TEXT_SEARCH, $params);

            if (! $resp->successful()) {
                Log::warning('Lead text search failed', ['status' => $resp->status(), 'page' => $page]);
                break;
            }

            $status = $resp->json('status');
            if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
                // Never include the key; log only Google's status field.
                Log::warning('Lead text search returned non-OK', ['google_status' => $status, 'page' => $page]);
                break;
            }

            foreach ($resp->json('results', []) as $r) {
                $all[] = $r;
            }

            $token = $resp->json('next_page_token');
            if (! $token || count($all) >= self::MAX_RESULTS) {
                break;
            }

            // Token isn't valid immediately; wait then page with it only.
            usleep(self::PAGE_TOKEN_DELAY_US);
            $params = ['pagetoken' => $token, 'key' => $apiKey];
        }

        return $all;
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
