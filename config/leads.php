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

];
