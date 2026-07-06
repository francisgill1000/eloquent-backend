<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lead Finder
    |--------------------------------------------------------------------------
    |
    | Active discovery source, per-tenant monthly search allowance (a shop may
    | override via shops.lead_search_allowance), and how long a cached search
    | stays fresh. Cache hits are free — they never consume the allowance.
    |
    */

    // Bound in AppServiceProvider::LEAD_SOURCES. Google's ToS restricts
    // reselling its data, so the production source will differ (e.g. explorium).
    'source' => env('LEAD_SOURCE', 'google_places'),

    'monthly_search_allowance' => (int) env('LEAD_MONTHLY_SEARCH_ALLOWANCE', 100),

    'cache_ttl_days' => (int) env('LEAD_CACHE_TTL_DAYS', 30),

    // Ad Activity results go stale faster than map data (campaigns change), so
    // they self-refresh weekly. Users can also force a fresh scrape any time.
    'ad_cache_ttl_days' => (int) env('LEAD_AD_CACHE_TTL_DAYS', 7),

    // Hard cap on how many ads the Apify scrape collects per run — the direct
    // Apify cost lever (billed per item). Low by default; after de-duping to one
    // lead per advertiser, expect a handful of businesses on top of Google.
    'ad_scrape_count' => (int) env('LEAD_AD_SCRAPE_COUNT', 10),

];
