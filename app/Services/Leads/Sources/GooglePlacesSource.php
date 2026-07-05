<?php

namespace App\Services\Leads\Sources;

use App\Services\Leads\Contracts\LeadSourceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Places API (New) — Text Search. One POST per page returns the business
 * name, address, location, rating, phone AND website together (no separate
 * Place Details call), and pagination via pageToken is reliable (the legacy
 * next_page_token never activates on New-API-only projects).
 *
 * Uses a SERVER-side, IP-restricted key from config('services.google_places.key')
 * sent in the X-Goog-Api-Key header — never logged or returned to the client.
 * A provider outage degrades to an empty result set rather than a 500.
 */
class GooglePlacesSource implements LeadSourceInterface
{
    private const SEARCH_TEXT = 'https://places.googleapis.com/v1/places:searchText';

    /** Field mask — only what we map to a lead DTO (plus the paging token). */
    private const FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,'
        . 'places.location,places.rating,places.types,places.internationalPhoneNumber,'
        . 'places.nationalPhoneNumber,places.websiteUri,nextPageToken';

    /** Google returns 20/page and caps a single query at ~60 (3 pages). */
    private const PAGE_SIZE = 20;
    private const MAX_PAGES = 3;
    private const MAX_RESULTS = 60;

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
            $out = [];
            foreach ($this->searchAllPages($text, $apiKey) as $p) {
                $out[] = $this->normalize($p);
            }
            return $out;
        } catch (\Throwable $e) {
            // Message may echo request details — log the class only, never the key.
            Log::warning('Lead search errored', ['exception' => get_class($e)]);
            return [];
        }
    }

    /**
     * Text Search across all pages (Google caps at ~60 / 3 pages). Paging
     * requires repeating the original body plus the pageToken. Any page-level
     * failure stops paging and returns what we have so far.
     *
     * @return array<int, array<string, mixed>> Raw New-API place objects.
     */
    private function searchAllPages(string $text, string $apiKey): array
    {
        $all = [];
        $body = ['textQuery' => $text, 'regionCode' => 'AE', 'pageSize' => self::PAGE_SIZE];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $resp = Http::timeout(10)
                ->retry(2, 200, throw: false)
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->post(self::SEARCH_TEXT, $body);

            if (! $resp->successful()) {
                // Log only Google's status text, never the key/headers.
                Log::warning('Lead text search failed', [
                    'http' => $resp->status(),
                    'google_status' => $resp->json('error.status'),
                    'page' => $page,
                ]);
                break;
            }

            foreach ($resp->json('places', []) as $place) {
                $all[] = $place;
            }

            $token = $resp->json('nextPageToken');
            if (! $token || count($all) >= self::MAX_RESULTS) {
                break;
            }
            $body['pageToken'] = $token; // all other params must stay identical
        }

        return array_slice($all, 0, self::MAX_RESULTS);
    }

    /** Map a New-API place object to a normalized lead DTO. */
    private function normalize(array $p): array
    {
        return [
            'name' => $p['displayName']['text'] ?? 'Unknown',
            'phone' => $p['internationalPhoneNumber'] ?? $p['nationalPhoneNumber'] ?? null,
            'website' => $p['websiteUri'] ?? null,
            'address' => $p['formattedAddress'] ?? null,
            'category' => $p['types'][0] ?? null,
            'lat' => $p['location']['latitude'] ?? null,
            'lng' => $p['location']['longitude'] ?? null,
            'rating' => isset($p['rating']) ? (float) $p['rating'] : null,
            'external_ref' => (string) ($p['id'] ?? ''),
            'source' => $this->key(),
        ];
    }
}
