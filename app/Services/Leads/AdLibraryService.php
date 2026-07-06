<?php

namespace App\Services\Leads;

use Illuminate\Support\Facades\Cache;
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
                // Hard cap on how many ads Apify collects per run — the direct
                // cost lever (Apify bills per item). Kept low on purpose; after
                // de-duping to one lead per advertiser this yields a handful of
                // businesses as a bonus on top of the Google listings. Raise the
                // LEAD_AD_SCRAPE_COUNT env var if fuller ad results are wanted.
                'count' => max(1, (int) config('leads.ad_scrape_count', 10)),
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
     *
     * Two async stages behind one runId so each poll stays a short request:
     *   1. Ad scrape — find advertisers (name + FB page).
     *   2. Contact enrichment — read each advertiser's page for phone/website/
     *      address, merge it in, then drop the ones with no usable contact.
     * When stage 1 finishes we kick off stage 2 and keep reporting 'running';
     * the client's existing 4s poll loop carries through both transparently.
     */
    public function poll(string $runId): array
    {
        $token = config('services.apify.token');
        $stateKey = "adsearch:enrich:{$runId}";

        // --- Stage 2: enrichment already running for this run ----------------
        $state = Cache::get($stateKey);
        if ($state !== null) {
            $status = $this->runStatus($token, $state['contact_run']);
            if (in_array($status, ['READY', 'RUNNING'], true)) {
                return ['status' => 'running', 'results' => []];
            }
            Cache::forget($stateKey);

            // Enrichment failed → fall back to the un-enriched leads (better than
            // nothing; no usable-contact filter so the list isn't emptied).
            if ($status !== 'SUCCEEDED') {
                return ['status' => 'done', 'results' => $state['results']];
            }

            $contacts = $this->fetchContactResults($token, $state['contact_dataset'] ?? null);
            $merged = $this->mergeContacts($state['results'], $contacts);
            return ['status' => 'done', 'results' => $this->keepUsable($merged)];
        }

        // --- Stage 1: the ad scrape ------------------------------------------
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

            $results = $this->fetchResults($token, $resp->json('data.defaultDatasetId'));

            // Kick off stage 2 (enrichment) and keep the client polling.
            if ($results && config('leads.ad_enrich', true)) {
                $started = $this->startContactRun($token, $results);
                if ($started !== null) {
                    Cache::put($stateKey, [
                        'contact_run' => $started['runId'],
                        'contact_dataset' => $started['datasetId'],
                        'results' => $results,
                    ], now()->addMinutes(15));
                    return ['status' => 'running', 'results' => []];
                }
            }

            // Enrichment off or failed to start → return the raw advertiser leads.
            return ['status' => 'done', 'results' => $results];
        } catch (\Throwable $e) {
            Log::warning('Ad Library poll errored', ['exception' => get_class($e)]);
            return ['status' => 'failed', 'results' => []];
        }
    }

    /** Current Apify run status (READY/RUNNING/SUCCEEDED/…); RUNNING on any error. */
    private function runStatus(string $token, string $runId): string
    {
        try {
            $resp = Http::timeout(15)->get(self::BASE . "/actor-runs/{$runId}?token={$token}");
            return $resp->successful() ? (string) $resp->json('data.status') : 'RUNNING';
        } catch (\Throwable) {
            return 'RUNNING';
        }
    }

    /**
     * Start stage 2: the Facebook page contact scraper over the advertiser pages
     * we just found. Returns ['runId','datasetId'] or null if it couldn't start.
     */
    private function startContactRun(string $token, array $leads): ?array
    {
        $actor = config('services.apify.page_contact_actor', 'apify~facebook-page-contact-information');

        $pages = [];
        foreach ($leads as $lead) {
            if (! empty($lead['page_url'])) {
                $pages[] = $lead['page_url'];
            }
        }
        $pages = array_values(array_unique($pages));
        if (empty($pages)) {
            return null;
        }

        try {
            $resp = Http::timeout(15)->retry(2, 300, throw: false)
                ->post(self::BASE . "/acts/{$actor}/runs?token={$token}", [
                    'pages' => $pages,
                    'language' => 'en-US',
                ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $resp->successful() || ! $resp->json('data.id')) {
            Log::warning('Contact enrichment failed to start', ['http' => $resp->status()]);
            return null;
        }

        return [
            'runId' => (string) $resp->json('data.id'),
            'datasetId' => (string) $resp->json('data.defaultDatasetId'),
        ];
    }

    /** Fetch stage-2 contact rows, keyed by normalized page URL for merging. */
    private function fetchContactResults(string $token, ?string $datasetId): array
    {
        if (! $datasetId) {
            return [];
        }

        $resp = Http::timeout(20)->get(self::BASE . "/datasets/{$datasetId}/items", [
            'token' => $token,
            'clean' => 'true',
            'fields' => 'pageUrl,phone,website,websites,address',
        ]);
        if (! $resp->successful()) {
            return [];
        }

        $byUrl = [];
        foreach ($resp->json() ?? [] as $row) {
            $url = $this->normUrl($row['pageUrl'] ?? '');
            if ($url !== '') {
                $byUrl[$url] = $row;
            }
        }
        return $byUrl;
    }

    /** Merge phone/website/address from the contact scrape into the ad leads. */
    private function mergeContacts(array $leads, array $byUrl): array
    {
        $out = [];
        foreach ($leads as $lead) {
            $c = $byUrl[$this->normUrl($lead['page_url'] ?? '')] ?? null;
            if ($c) {
                $phone = $this->firstString($c['phone'] ?? null);
                $website = $this->firstString($c['website'] ?? null) ?: $this->firstString($c['websites'] ?? null);
                $address = $this->firstString($c['address'] ?? null);

                if ($phone) {
                    $lead['phone'] = $phone;
                    $lead['whatsapp'] = $phone;
                }
                if ($website) {
                    $lead['website'] = $website; // a real site beats the FB-page fallback
                }
                if ($address) {
                    $lead['address'] = preg_replace('/\s+/', ' ', $address);
                }
            }
            $out[] = $lead;
        }
        return $out;
    }

    /**
     * Keep only advertisers we can actually act on — a phone, or a real website
     * (the Facebook-page fallback doesn't count). Applied only after enrichment,
     * so a scrape that returns no contacts still shows its name-only leads.
     */
    private function keepUsable(array $leads): array
    {
        return array_values(array_filter($leads, function ($l) {
            if (! empty($l['phone'])) {
                return true;
            }
            $w = $l['website'] ?? null;
            return $w && ! str_contains($w, 'facebook.com');
        }));
    }

    private function normUrl(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }

    /** Contact fields can arrive as a string or an array; take the first value. */
    private function firstString($value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        $value = is_string($value) ? trim($value) : null;
        return $value !== null && $value !== '' ? $value : null;
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
            // `snapshot` carries the real page profile URL, which we feed to the
            // contact scraper (the ad's own pageId sometimes differs from it).
            'fields' => 'pageName,pageId,linkUrl,pageCategories,isActive,snapshot',
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

            $profileUri = $ad['snapshot']['page_profile_uri'] ?? null;

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
                // Transient: used by stage-2 enrichment, not persisted as a column.
                'page_url' => $profileUri ?: "https://www.facebook.com/{$pageId}/",
            ];
        }

        return $out;
    }

    // --- Caching ----------------------------------------------------------
    // Reuses the same global tables Google uses (lead_place_cache keyed by
    // "fb:{pageId}", lead_search_cache keyed by the keyword). A repeat search
    // is served free + instant with no re-scrape — no Apify cost, no quota spent.

    /**
     * Fresh cached results for this keyword, or null on miss.
     *
     * @return array{results: array, fetched_at: string}|null
     */
    public function cachedResults(string $keyword): ?array
    {
        $ttlDays = (int) config('leads.ad_cache_ttl_days', 7);
        $row = DB::table('lead_search_cache')
            ->where('source', 'meta_ad_library')
            ->where('query_key', $this->cacheKey($keyword))
            ->where('fetched_at', '>=', now()->subDays($ttlDays))
            ->first();

        if (! $row) {
            return null;
        }
        return [
            'results' => $this->hydrate(json_decode($row->external_refs, true) ?: []),
            'fetched_at' => (string) $row->fetched_at,
        ];
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
                    'phone' => $r['phone'] ?? null,
                    'website' => $r['website'] ?? null,
                    'address' => $r['address'] ?? null,
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
                'phone' => $row->phone,
                'whatsapp' => $row->phone,
                'website' => $row->website,
                'address' => $row->address,
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
