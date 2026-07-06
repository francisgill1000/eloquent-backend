<?php

namespace App\Services\Leads;

use App\Models\Shop;
use App\Services\Leads\Contracts\LeadSourceInterface;
use App\Services\Leads\Exceptions\SearchLimitReached;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates business discovery: cache-first, then credit-gated live calls.
 *
 * The two caches are GLOBAL (public business data, shared across shops like a
 * CDN). Only LIVE source calls consume a shop's monthly allowance and write a
 * usage-log row — cache hits are free. This is what lets a shop re-run the same
 * search without burning credits.
 */
class LeadSearchService
{
    public function __construct(private LeadSourceInterface $source)
    {
    }

    /**
     * @return array{results: array, from_cache: bool, used: int, limit: int, remaining: int}
     *
     * @throws SearchLimitReached when the monthly allowance is exhausted.
     */
    public function search(Shop $shop, string $query, ?string $area): array
    {
        $ttlDays = (int) config('leads.cache_ttl_days', 0);
        $queryKey = $this->queryKey($query, $area);

        // 1. Query cache — a repeat search is served free from the place cache.
        // ttlDays <= 0 means the cache never expires (served forever until it's
        // cleared manually); a positive value re-enables refresh after N days.
        $cached = DB::table('lead_search_cache')
            ->where('source', $this->source->key())
            ->where('query_key', $queryKey)
            ->when($ttlDays > 0, fn ($q) => $q->where('fetched_at', '>=', now()->subDays($ttlDays)))
            ->first();

        if ($cached) {
            $refs = json_decode($cached->external_refs, true) ?: [];
            [$used, $limit] = $this->usage($shop);
            return [
                'results' => $this->hydrateFromCache($refs),
                'from_cache' => true,
                'used' => $used,
                'limit' => $limit,
                'remaining' => max(0, $limit - $used),
            ];
        }

        // 2. Cache miss — enforce the monthly allowance before spending money.
        [$used, $limit] = $this->usage($shop);
        if ($used >= $limit) {
            throw new SearchLimitReached($used, $limit);
        }

        // 3. Live call.
        $results = $this->source->search($query, $area);

        $this->persistCaches($query, $area, $queryKey, $results);

        DB::table('lead_search_logs')->insert([
            'shop_id' => $shop->id,
            'query' => $query,
            'area' => $area,
            'results_count' => count($results),
            'created_at' => now(),
        ]);

        $used++;

        return [
            'results' => $results,
            'from_cache' => false,
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    /** Record one billable search against the shop's monthly quota. */
    public function recordSearch(Shop $shop, string $query, ?string $area, int $resultsCount = 0): void
    {
        DB::table('lead_search_logs')->insert([
            'shop_id' => $shop->id,
            'query' => $query,
            'area' => $area,
            'results_count' => $resultsCount,
            'created_at' => now(),
        ]);
    }

    /** @return array{0:int,1:int} [used this month, limit] */
    public function usage(Shop $shop): array
    {
        $limit = $shop->lead_search_allowance
            ?? (int) config('leads.monthly_search_allowance', 100);

        $used = DB::table('lead_search_logs')
            ->where('shop_id', $shop->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return [$used, (int) $limit];
    }

    private function queryKey(string $query, ?string $area): string
    {
        return md5(strtolower(trim($query)) . '|' . strtolower(trim((string) $area)));
    }

    /** Upsert place rows + the query->refs mapping. */
    private function persistCaches(string $query, ?string $area, string $queryKey, array $results): void
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
                    'source' => $r['source'] ?? $this->source->key(),
                    'name' => $r['name'] ?? 'Unknown',
                    'phone' => $r['phone'] ?? null,
                    'website' => $r['website'] ?? null,
                    'address' => $r['address'] ?? null,
                    'category' => $r['category'] ?? null,
                    'lat' => $r['lat'] ?? null,
                    'lng' => $r['lng'] ?? null,
                    'rating' => $r['rating'] ?? null,
                    'raw' => json_encode($r),
                    'fetched_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('lead_search_cache')->updateOrInsert(
            ['query_key' => $queryKey],
            [
                'source' => $this->source->key(),
                'query' => $query,
                'area' => $area,
                'external_refs' => json_encode($refs),
                'fetched_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /** Rebuild normalized DTOs from the place cache, preserving result order. */
    private function hydrateFromCache(array $refs): array
    {
        if (empty($refs)) {
            return [];
        }

        $rows = DB::table('lead_place_cache')
            ->whereIn('external_ref', $refs)
            ->get()
            ->keyBy('external_ref');

        $out = [];
        foreach ($refs as $ref) {
            $row = $rows->get($ref);
            if (! $row) {
                continue;
            }
            $out[] = [
                'name' => $row->name,
                'phone' => $row->phone,
                'website' => $row->website,
                'address' => $row->address,
                'category' => $row->category,
                'lat' => $row->lat !== null ? (float) $row->lat : null,
                'lng' => $row->lng !== null ? (float) $row->lng : null,
                'rating' => $row->rating !== null ? (float) $row->rating : null,
                'external_ref' => $row->external_ref,
                'source' => $row->source,
            ];
        }
        return $out;
    }
}
