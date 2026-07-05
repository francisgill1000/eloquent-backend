# Lead Finder — Design Spec

**Date:** 2026-07-05
**Status:** Approved for planning
**Scope:** Backend only. Tenant-scoped module that lets a shop find real UAE
businesses, save them as leads, and work them with status + follow-up tracking
(WhatsApp / call actions).

---

## 1. North star & non-goals (anti-CRM)

Lead Finder is a **purpose-built UAE prospecting tool**, not a generic CRM.
Mental model: *search UAE businesses off the map → save → WhatsApp/call → track
to won.* A prospecting sniper, not a configurable database.

**What keeps it from feeling generic:**

- **Discovery-first, not data-entry.** The primary action is searching real UAE
  businesses via a maps data source and saving them — not typing contacts by hand.
- **One flat object.** Just `leads`. No Accounts / Contacts / Deals / Companies
  object sprawl, no custom fields, no configurable pipelines.
- **Fixed, opinionated funnel:** `new → sent → replied → demo → won → pass`.
  Encodes one workflow: cold-outreach-to-close. Not user-configurable.
- **Action-first, WhatsApp-native.** Verbs are *WhatsApp this lead* / *call this
  lead* (UAE `9715…` mobile detection, `wa.me` deep links). Not "log an activity."
- **Follow-up = "who's due today,"** not a sequences/automation/calendar engine.

**Hard non-goals — will NOT be built:** custom fields, multiple pipelines,
contact/account separation, email integration, task/calendar system, bulk import,
tags/segments builder. Any of these later is a deliberate decision, not a default.

## 2. Tenancy & auth

- Tenant = the authenticated **`Shop`** (`Shop` is the Sanctum authenticatable).
- All routes under `auth:sanctum`. Tenant id derived from `$request->user()->id`
  — **never** a `?shop_id=` query param for reads or writes (multi-tenant
  isolation principle).
- Every `leads` / `lead_activities` / `lead_search_logs` query is filtered by
  `shop_id`. A `Lead::scopeForShop($id)` helper keeps this consistent.
- `lead_activities.user_id` = the acting **ShopUser** via
  `CurrentShopUser::get()?->id` (RBAC context set by `SetRbacContext`), nullable
  (a bare Shop token with no shop_user still works).

## 3. Data model (migrations)

House style: `unsignedBigInteger('shop_id')` (no FK constraint), date-prefixed
filenames `YYYY_MM_DD_00000N`, string enums with `->default()` + inline comment.

### 3.1 `leads` (tenant-scoped)
`id`, `shop_id`, `name`, `phone` (null), `whatsapp` (null), `website` (null),
`address` (null), `category` (null), `lat` (decimal null), `lng` (decimal null),
`source` (string, e.g. `google_places`), `external_ref` (string null),
`status` string default `new` (`new|sent|replied|demo|won|pass`),
`notes` text null, `last_contacted_at` timestamp null,
`next_followup_at` timestamp null, timestamps.
Indexes: `index(['shop_id','status'])`; `unique(['shop_id','external_ref'])`
(dedupe on save; nullable external_ref allowed — enforced in app for manual adds).

### 3.2 `lead_activities` (audit log of every touch)
`id`, `lead_id`, `type` string (`status_change|note|contacted`),
`payload` json null, `user_id` (unsignedBigInteger null), timestamps.
Index `index(['lead_id'])`.

### 3.3 `lead_search_logs` (billing counter + usage log)
`id`, `shop_id`, `query`, `area` (null), `results_count` (uint default 0),
`created_at`. Written **only on a live (billable) source call** — cache hits do
not write a row and do not consume allowance. Monthly usage =
`count where shop_id AND created_at >= startOfMonth`.
Index `index(['shop_id','created_at'])`.

### 3.4 `lead_place_cache` (global public-data cache — NOT tenant data)
`id`, `source`, `external_ref` (unique), `name`, `phone` (null),
`website` (null), `address` (null), `category` (null), `lat`/`lng` (null),
`rating` (null), `raw` json null, `fetched_at`, timestamps.
Public business info shared across tenants like a CDN to maximise savings.
Distinct from `leads` (which are tenant-owned copies at save time).

### 3.5 `lead_search_cache` (global query→results cache)
`id`, `source`, `query_key` (unique = `md5(lower(trim(query)).'|'.lower(trim(area)))`),
`query`, `area` (null), `external_refs` json, `fetched_at`, timestamps.

### 3.6 `shops` alteration
Add `lead_search_allowance` (unsignedInteger null) — per-tenant override of the
config default.

## 4. Source abstraction (pluggable)

```
App\Services\Leads\Contracts\LeadSourceInterface
    public function search(string $query, ?string $area): array;  // normalized DTOs
```

Normalized DTO (assoc array): `name, phone, website, address, category, lat, lng,
rating, external_ref, source`.

- `App\Services\Leads\Sources\GooglePlacesSource` — Places API (New):
  `places:searchText` (POST, field-masked) returns name/address/location/rating/
  phone/website in one call and paginates via `pageToken` to ~60 results (3
  pages). (Legacy `textsearch` was tried first but its `next_page_token` never
  activates on New-API-only projects, capping results at 20.)
  - Server-side key from `config('services.google_places.key')`
    (env `GOOGLE_PLACES_KEY`) — separate from `services.google_maps.key`.
    Key never logged; never returned to client.
  - All HTTP via `Http::timeout(8)->retry(2, 200)` inside try/catch; on failure
    return `[]` (or throw a domain exception the service turns into a graceful
    error) — never a 500.
- Active source resolved in a service provider from `config('leads.source')`
  (default `google_places`). `ExploriumSource` drops in later by adding a class
  + flipping config — no controller changes (Google ToS restricts reselling, so
  production source will differ).

## 5. Search service — cache-first, credit-gated

`App\Services\Leads\LeadSearchService::search(Shop $shop, string $query, ?string $area): array`

1. `query_key = md5(...)`. Look up `lead_search_cache` within TTL
   (`config('leads.cache_ttl_days')`, default 30).
   **Hit → hydrate DTOs from `lead_place_cache` by `external_refs`. No source
   call. No allowance consumed.** Return `{results, from_cache:true, remaining}`.
2. **Miss → allowance check.** `used = count(lead_search_logs this month)`;
   `limit = shop.lead_search_allowance ?? config('leads.monthly_search_allowance')`
   (100). If `used >= limit` → throw `SearchLimitReached` →
   **HTTP 429 `{error:'search_limit_reached', used, limit}`** (429 not 402 —
   402 is reserved for the subscription paywall, which the admin SPA's axios
   interceptor redirects to `/subscribe`; a quota limit must stay on the page).
3. Under cap → call active source. For each place, reuse a fresh
   `lead_place_cache` row if present (skips a Place Details call), else fetch +
   upsert. Write `lead_search_cache` row + one `lead_search_logs` row
   (results_count). Return `{results, from_cache:false, remaining}`.

Search returns normalized results only — **never auto-saves**.

## 6. Model enrichment (accessors on `Lead`)

Phone normalization → international digits (strip non-digits; `0xxxxxxxxx` UAE
local → `971xxxxxxxxx`; leading `00` → strip; keep already-`971…`).

- `whatsapp_url` — `https://wa.me/{digits}` (uses `whatsapp` col, falls back to
  `phone`).
- `is_mobile` — bool: normalized digits start with `9715` (UAE mobile).
  WhatsApp only valid when true.
- `tel_url` — `tel:+{digits}`.
- `map_url` — from lat/lng (`https://www.google.com/maps/search/?api=1&query=lat,lng`),
  null if no coords.

Appended to JSON via `$appends`.

## 7. Endpoints (`LeadController`, all `auth:sanctum`)

Route group prefix `/shop/leads`, matching the `/shop/...` admin convention.

- `GET  /shop/leads/search?category=&area=` → calls `LeadSearchService`,
  returns normalized results + `{from_cache, remaining}`. Does not save.
- `POST /shop/leads` → body `{leads: [DTO...]}` (or `{external_refs: [...]}`);
  persists selected as leads for the current shop; **dedupe on
  `(shop_id, external_ref)`** (upsert / skip existing). Returns saved rows.
- `PATCH /shop/leads/{lead}/status` → body `{status, note?}`; validates status
  in enum; updates status; writes a `lead_activities` (`status_change`) row;
  bumps `last_contacted_at = now()`. Route-model-bound lead re-checked for
  `shop_id` ownership (404 otherwise).
- `GET  /shop/leads?status=&category=&search=&followups=due` → tenant leads with
  filters (`search` matches name/phone/address) + **funnel counts per status**
  (`{new: n, sent: n, ...}`). `followups=due` → `next_followup_at <= today AND
  status in [sent, replied]`.

Ownership guard: every `{lead}` binding verified `->where('shop_id', $shop->id)`.

## 8. Follow-up engine

`php artisan leads:due-followups` — registered `->daily()` in `routes/console.php`
(alongside `invoices:update-overdue-status`).

- For each shop, find leads where `next_followup_at <= today` and
  `status in [sent, replied]`. Log the per-shop count.
- Surfaced to the frontend via the `GET /shop/leads?followups=due` filter (the
  "Due today" queue). **No push in v1** (app has web-push infra; wired later if
  needed) — keeps it minimal.

## 9. Credit gating (protect API cost)

- Config default `config('leads.monthly_search_allowance')` = **100**,
  overridable per shop via `shops.lead_search_allowance`.
- Consumed **only on live source calls** (cache hits are free — this is what the
  place/search caches buy us).
- Exhausted → 429 `search_limit_reached`. Every live call logged in
  `lead_search_logs` (usage log doubles as the counter).

## 10. Config & env

- `config/leads.php`:
  ```php
  return [
      'source' => env('LEAD_SOURCE', 'google_places'),
      'monthly_search_allowance' => (int) env('LEAD_MONTHLY_SEARCH_ALLOWANCE', 100),
      'cache_ttl_days' => (int) env('LEAD_CACHE_TTL_DAYS', 30),
  ];
  ```
- `config/services.php`: add
  ```php
  'google_places' => [
      // Enable "Places API" in Google Cloud. Use a SERVER-side, IP-restricted key
      // (NOT the referrer-restricted frontend Maps key in google_maps.key).
      'key' => env('GOOGLE_PLACES_KEY'),
  ],
  ```
- `.env.example`: add `GOOGLE_PLACES_KEY=` with the same comment.

## 11. Quality / testing

- All external HTTP wrapped in try/catch with timeouts + retries; graceful `[]`
  on failure; key never logged.
- **Feature test** (`tests/Feature/LeadFinderTest.php`): bind a fake
  `LeadSourceInterface` in the container; assert the flow
  **search → save → status-update → activity-logged**, and that it stays
  **tenant-scoped** (shop B cannot read/patch shop A's leads; funnel counts are
  per-shop). Assert cache hit consumes no allowance; assert allowance exhaustion
  returns 429.

## 12. File manifest

```
database/migrations/2026_07_05_0001_create_leads_table.php
database/migrations/2026_07_05_0002_create_lead_activities_table.php
database/migrations/2026_07_05_0003_create_lead_search_logs_table.php
database/migrations/2026_07_05_0004_create_lead_place_cache_table.php
database/migrations/2026_07_05_0005_create_lead_search_cache_table.php
database/migrations/2026_07_05_0006_add_lead_search_allowance_to_shops.php
app/Models/Lead.php
app/Models/LeadActivity.php
app/Services/Leads/Contracts/LeadSourceInterface.php
app/Services/Leads/Sources/GooglePlacesSource.php
app/Services/Leads/LeadSearchService.php
app/Services/Leads/Exceptions/SearchLimitReached.php
app/Providers/LeadsServiceProvider.php   (or bind in AppServiceProvider)
app/Console/Commands/DueFollowups.php
app/Http/Controllers/LeadController.php
config/leads.php
config/services.php            (add google_places)
.env.example                   (add GOOGLE_PLACES_KEY)
routes/api.php                 (add /shop/leads* group)
routes/console.php             (schedule leads:due-followups)
tests/Feature/LeadFinderTest.php
```

## 13. Local-env note

Dev box has no PHP 8.2 / Composer — cannot run migrations or phpunit locally.
Verification (lint + `php artisan test` + a tinker smoke) happens on the droplet
after push, per the "verify backend on droplet" workflow.
