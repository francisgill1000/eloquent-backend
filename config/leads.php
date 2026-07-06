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

    // How many days a cached search stays valid. 0 = NEVER expires: once a query
    // is cached it is served from cache forever (no re-fetch, no cost) until the
    // cache is cleared manually. This is the default to minimise provider cost.
    // Set a positive number to re-enable automatic refresh after that many days.
    'cache_ttl_days' => (int) env('LEAD_CACHE_TTL_DAYS', 0),

    // Same for the Ad Activity cache. 0 = never expires (manual clear only).
    'ad_cache_ttl_days' => (int) env('LEAD_AD_CACHE_TTL_DAYS', 0),

    // Hard cap on how many ads the Apify scrape collects per run — the direct
    // Apify cost lever (billed per item). Low by default; after de-duping to one
    // lead per advertiser, expect a handful of businesses on top of Google.
    'ad_scrape_count' => (int) env('LEAD_AD_SCRAPE_COUNT', 10),

    // Second-stage enrichment: after the ad scrape finds advertisers, read each
    // one's Facebook page for phone/website/address (~$0.013/page). Turn off to
    // save that cost — ad leads then show name + link only. Bounded by the
    // ad_scrape_count cap above and cached like everything else.
    'ad_enrich' => (bool) env('LEAD_AD_ENRICH', true),

];
