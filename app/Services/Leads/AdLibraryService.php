<?php

namespace App\Services\Leads;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Ad Activity" lead source — surfaces UAE businesses actively running Meta
 * (Facebook/Instagram) ads, via an Apify Ad Library scraper actor.
 *
 * The scrape takes ~60-90s, so this is ASYNC: start() kicks off an Apify run and
 * returns a run id; poll() reports status and, once finished, returns normalized
 * lead DTOs. v1 does NO Google enrichment — leads carry name + category + best
 * link (website / WhatsApp / Facebook page). Phone enrichment can be layered later.
 *
 * The Apify token is server-side only (config('services.apify.token')) and never
 * logged or returned to the client.
 */
class AdLibraryService
{
    private const BASE = 'https://api.apify.com/v2';

    /** How many ads to scrape per run (bounds Apify cost; ~40 ads → ~15-20 pages). */
    private const AD_COUNT = 40;

    public function configured(): bool
    {
        return (bool) config('services.apify.token');
    }

    /**
     * Start an Ad Library scrape. Returns the Apify run id.
     *
     * @throws \RuntimeException when Apify is unreachable / rejects the run.
     */
    public function start(string $query, ?string $area): string
    {
        $token = config('services.apify.token');
        $actor = config('services.apify.ad_library_actor', 'constructive_calm~facebook-ad-library-pro');

        // Keyword only — the Ad Library matches this against ad CREATIVE TEXT,
        // not location, and the country filter is already AE. Appending an area
        // (e.g. "salon sharjah") would require the ad copy to contain that word
        // and drastically over-filters, so $area is intentionally ignored here.
        $keyword = trim($query);

        $resp = Http::timeout(15)
            ->retry(2, 300, throw: false)
            ->post(self::BASE . "/acts/{$actor}/runs?token={$token}", [
                'keyword' => $keyword,
                'country' => 'AE',
                'activeStatus' => 'active',
                'count' => self::AD_COUNT,
                'resolveAdvertiser' => false,
                // Off, else a repeat identical search resumes as "already done"
                // and returns an empty dataset. Each run must scrape fresh.
                'enableCheckpoint' => false,
            ]);

        if (! $resp->successful() || ! $resp->json('data.id')) {
            Log::warning('Ad Library run failed to start', ['http' => $resp->status()]);
            throw new \RuntimeException('Could not start the ad search.');
        }

        return (string) $resp->json('data.id');
    }

    /**
     * Poll a run. Returns ['status' => 'running'|'done'|'failed', 'results' => array].
     * Results are normalized lead DTOs (only when status is 'done').
     */
    public function poll(string $runId): array
    {
        $token = config('services.apify.token');

        try {
            $resp = Http::timeout(15)->get(self::BASE . "/actor-runs/{$runId}?token={$token}");
            if (! $resp->successful()) {
                return ['status' => 'running', 'results' => []];
            }

            $status = $resp->json('data.status');

            if (in_array($status, ['READY', 'RUNNING'], true)) {
                return ['status' => 'running', 'results' => []];
            }

            if ($status !== 'SUCCEEDED') {
                Log::warning('Ad Library run did not succeed', ['status' => $status]);
                return ['status' => 'failed', 'results' => []];
            }

            $datasetId = $resp->json('data.defaultDatasetId');
            return ['status' => 'done', 'results' => $this->fetchResults($token, $datasetId)];
        } catch (\Throwable $e) {
            Log::warning('Ad Library poll errored', ['exception' => get_class($e)]);
            return ['status' => 'failed', 'results' => []];
        }
    }

    /** Fetch the dataset, dedupe advertisers, and normalize to lead DTOs. */
    private function fetchResults(string $token, ?string $datasetId): array
    {
        if (! $datasetId) {
            return [];
        }

        $resp = Http::timeout(20)->get(self::BASE . "/datasets/{$datasetId}/items", [
            'token' => $token,
            'clean' => 'true',
            'fields' => 'pageName,pageId,linkUrl,pageCategories,isActive',
            'limit' => 100,
        ]);

        if (! $resp->successful()) {
            return [];
        }

        $seen = [];
        $out = [];
        foreach ($resp->json() ?? [] as $ad) {
            $pageId = $ad['pageId'] ?? null;
            $name = $ad['pageName'] ?? null;
            if (! $pageId || ! $name || isset($seen[$pageId])) {
                continue; // dedupe: one lead per advertiser page
            }
            $seen[$pageId] = true;

            $out[] = [
                'name' => $name,
                'phone' => null,
                'whatsapp' => null,
                'website' => $this->bestLink($ad['linkUrl'] ?? null, $pageId),
                'address' => null,
                'category' => $ad['pageCategories'][0] ?? null,
                'lat' => null,
                'lng' => null,
                'rating' => null,
                'external_ref' => "fb:{$pageId}",
                'source' => 'meta_ad_library',
                'advertising' => true,
            ];
        }

        return $out;
    }

    // --- Caching ----------------------------------------------------------
    // Reuses the same global tables Google uses (lead_place_cache keyed by
    // "fb:{pageId}", lead_search_cache keyed by the keyword). A repeat search
    // is served free + instant with no re-scrape — no Apify cost, no quota spent.

    /** Fresh cached results for this keyword, or null on miss. */
    public function cachedResults(string $keyword): ?array
    {
        $ttlDays = (int) config('leads.cache_ttl_days', 30);
        $row = DB::table('lead_search_cache')
            ->where('source', 'meta_ad_library')
            ->where('query_key', $this->cacheKey($keyword))
            ->where('fetched_at', '>=', now()->subDays($ttlDays))
            ->first();

        if (! $row) {
            return null;
        }
        return $this->hydrate(json_decode($row->external_refs, true) ?: []);
    }

    /** Persist a finished run's results so the next identical search is a hit. */
    public function cacheResults(string $keyword, array $results): void
    {
        $refs = [];
        foreach ($results as $r) {
            if (empty($r['external_ref'])) {
                continue;
            }
            $refs[] = $r['external_ref'];
            DB::table('lead_place_cache')->updateOrInsert(
                ['external_ref' => $r['external_ref']],
                [
                    'source' => 'meta_ad_library',
                    'name' => $r['name'] ?? 'Unknown',
                    'phone' => null,
                    'website' => $r['website'] ?? null,
                    'address' => null,
                    'category' => $r['category'] ?? null,
                    'lat' => null,
                    'lng' => null,
                    'rating' => null,
                    'raw' => json_encode($r),
                    'fetched_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('lead_search_cache')->updateOrInsert(
            ['query_key' => $this->cacheKey($keyword)],
            [
                'source' => 'meta_ad_library',
                'query' => $keyword,
                'area' => null,
                'external_refs' => json_encode($refs),
                'fetched_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function cacheKey(string $keyword): string
    {
        return md5('meta_ad_library|' . strtolower(trim($keyword)));
    }

    /** Rebuild DTOs from the place cache, preserving order + advertising flag. */
    private function hydrate(array $refs): array
    {
        if (empty($refs)) {
            return [];
        }
        $rows = DB::table('lead_place_cache')->whereIn('external_ref', $refs)->get()->keyBy('external_ref');

        $out = [];
        foreach ($refs as $ref) {
            $row = $rows->get($ref);
            if (! $row) {
                continue;
            }
            $out[] = [
                'name' => $row->name,
                'phone' => null,
                'whatsapp' => null,
                'website' => $row->website,
                'address' => null,
                'category' => $row->category,
                'lat' => null,
                'lng' => null,
                'rating' => null,
                'external_ref' => $row->external_ref,
                'source' => 'meta_ad_library',
                'advertising' => true,
            ];
        }
        return $out;
    }

    /**
     * Pick the most useful link: a real destination URL, else the advertiser's
     * Facebook page. Generic "no-op" links (WhatsApp send, bare Instagram) fall
     * back to the FB page since they carry no business-specific target.
     */
    private function bestLink(?string $linkUrl, string $pageId): string
    {
        $fbPage = "https://www.facebook.com/{$pageId}";

        if (! $linkUrl || ! str_starts_with($linkUrl, 'http')) {
            return $fbPage;
        }
        $generic = ['api.whatsapp.com/send', 'wa.me', 'l.facebook.com', 'facebook.com/ads'];
        foreach ($generic as $g) {
            if (str_contains($linkUrl, $g)) {
                return $fbPage;
            }
        }
        // Bare social roots (instagram.com/ with no handle) aren't useful.
        if (rtrim($linkUrl, '/') === 'https://www.instagram.com') {
            return $fbPage;
        }

        return $linkUrl;
    }
}
