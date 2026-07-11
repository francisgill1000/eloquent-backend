# Product-aware AI dashboard summary

**Date:** 2026-07-12
**Status:** Approved design, pending implementation plan

## Problem

The dashboard AI Summary (`/ai-summary`, powered by `AiInsightsWriter`) is
bookings-only:

- **Hard gate** (`AiInsightsWriter.php:48`): fewer than 5 *bookings* in the period
  → returns `low_data` ("Not enough bookings…"). A leads-only (Business Hunt) shop
  has zero bookings, so it can never produce a summary.
- **Booking-only payload** (`buildPayload`): bookings, revenue, customers, reviews,
  top services. No Hunt pipeline data at all — even a mixed shop's summary ignores
  Hunt.
- **Nightly pre-generation** (`GenerateDailyAiSummaries::activeShopIds`) only
  iterates shops with **bookings** in the window, so a leads-only shop never gets a
  stored morning summary.

Yet the AI Summary tile is shown to `leads` shops (`DesktopSidebar.tsx:20`,
`modules: BOTH`). So a Hunt shop sees the tile and hits a permanent dead-end.

## Goal

Make the AI summary product-aware: a shop is summarized on whichever module(s) it
has (`bookings` and/or `leads`), with a per-product minimum-data gate, and the
nightly job pre-generates for Hunt shops too. No frontend change, no DB schema
change.

## Non-goals

- No changes to the booking summary's existing behaviour for bookings-only shops.
- No new `ai_summaries` columns — the response contract
  (`summary`/`patterns`/`recommendations`) is domain-agnostic already.
- No frontend changes (the tile already shows for `leads` shops).

## Architecture

### 1. `ReportsAggregator::huntSummary(int $shopId, Carbon $from, Carbon $to): array`

New method, tenant-scoped, shaped like `insightsSummary`. All queries filter by
`$shopId` (via `leads.shop_id`; note `lead_activities` has **no** `shop_id` and
must join through `leads` on `lead_id`).

Returns:

```php
[
    'range'    => ['from' => 'Y-m-d', 'to' => 'Y-m-d'],
    'new_leads'   => <int>,                 // leads.created_at in [from,to]
    'pipeline'    => [                       // CURRENT snapshot, count by status
        'new' => n, 'sent' => n, 'replied' => n, 'demo' => n, 'won' => n, 'pass' => n,
    ],
    'total_leads' => <int>,                  // sum of pipeline (whole pipeline now)
    'moved'       => [                       // status CHANGES in [from,to], by target
        'sent' => n, 'replied' => n, 'demo' => n, 'won' => n, 'pass' => n,
    ],
    'won'         => <int>,                  // moved['won'] (convenience)
    'credits_used'=> <int>,                  // abs sum of hunt_credit_transactions
                                             //   reason='search' in [from,to]
    'searches'    => <int>,                  // lead_search_logs rows in [from,to]
]
```

Query notes (Postgres):
- `pipeline`: `leads` where `shop_id`, `GROUP BY status`, zero-filled over
  `Lead::STATUSES`.
- `new_leads`: `leads` where `shop_id` and `created_at` between `$from`/`$to`.
- `moved`: `lead_activities` join `leads` on `lead_activities.lead_id = leads.id`,
  `leads.shop_id = ?`, `lead_activities.type = 'status_change'`,
  `lead_activities.created_at` between `$from`/`$to`, grouped by the JSON target
  `payload->>'to'` (zero-filled over the target statuses).
- `credits_used`: `hunt_credit_transactions` where `shop_id`, `reason = 'search'`,
  `created_at` between → `abs(sum(amount))` (search debits are negative).
- `searches`: `lead_search_logs` where `shop_id`, `created_at` between → `count`.

### 2. `AiInsightsWriter` becomes product-aware

`summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh)` — unchanged
signature. Internally:

- Load the shop (`Shop::find($shopId)`) to read `hasModule('bookings')` /
  `hasModule('leads')` (master shops: treat as having all modules).
- **Per-product gates** (constants):
  - `MIN_BOOKINGS = 5` (unchanged): bookings qualifies when the shop has the
    `bookings` module and `insights.bookings.scheduled >= 5`.
  - `MIN_HUNT_ACTIONS = 5` (new): hunt qualifies when the shop has the `leads`
    module and `hunt.new_leads + (sum of hunt.moved) >= 5`.
- **Generate when ANY present module clears its gate.** If none do → `low_data`
  with a product-appropriate message:
  - leads-only shop → "Not enough Business Hunt activity in this period yet to
    generate an AI summary. Check back once you have a few more leads."
  - bookings-only (or mixed) → the existing bookings message.
- **Payload** (`buildPayload`) includes a `bookings` block only when bookings
  qualifies, and a `hunt` block only when hunt qualifies — each with `current` vs
  `previous` equal-length period (previous computed exactly as today). A mixed shop
  that clears both gates gets both blocks; the model summarizes what is present.
  - Note: `pipeline` and `total_leads` are a **current** snapshot (not
    date-bounded), so they appear only in the hunt `current` block. The hunt
    `previous` block carries only the period-bound metrics (`new_leads`, `moved`,
    `won`, `credits_used`, `searches`) — omit `pipeline`/`total_leads` there to
    avoid the model "comparing" two identical snapshots.
- **System prompt** generalized: "a service business that may take bookings and/or
  run a Business Hunt lead pipeline… summarize only the sections present in the
  JSON… money in AED." Same strict "use only the numbers provided" rules and the
  same JSON output contract (`summary`/`patterns`/`recommendations`) — so caching,
  `persistDaily`, and `fromStored` are unchanged.

### 3. Nightly job `GenerateDailyAiSummaries::activeShopIds`

Return the **union** of:
- shops (`status='active'`) with a booking in the window (existing query), and
- shops (`status='active'`) with Hunt activity in the window: a `leads` row with
  `created_at >= $fromDate`, OR a `lead_activities` row (`type='status_change'`)
  in the window joined through `leads`.

Deduplicate the ids. The writer's per-product gate then still skips a paid Claude
call for shops that are present but too quiet. Update the command's docblock
(currently "Only shops with bookings…").

### 4. Frontend

No change. The tile already renders for `leads` shops; it now yields a real
summary.

## Testing

- **`ReportsAggregator` (Hunt)**: `huntSummary` returns correct `new_leads`,
  `pipeline` snapshot, `moved` counts from `lead_activities` (incl. the join and
  the `payload->>'to'` grouping), `won`, `credits_used`, `searches`; all
  tenant-scoped (a second shop's leads/activities never leak in).
- **`AiInsightsWriter` (product-aware)** — with `ClaudeClient` faked/mocked so no
  network:
  - leads-only shop with ≥5 Hunt actions → generates (state `ok`), payload carries
    a `hunt` block and no `bookings` block.
  - leads-only shop with <5 Hunt actions → `low_data` with the Hunt message.
  - bookings-only shop → unchanged behaviour (existing tests still pass).
  - mixed shop clearing both gates → payload carries both blocks.
- **`GenerateDailyAiSummaries`**: a leads-only shop with in-window Hunt activity is
  included in `activeShopIds` (previously excluded); a dormant leads shop with no
  activity is not.

Tests run on the droplet (php8.4) against a scratch/sqlite DB — never local, never
prod (memories `run-tests-on-droplet`, `never-run-tests-against-prod-db`).

## Files

**Modified:** `app/Services/Reports/ReportsAggregator.php` (add `huntSummary`),
`app/Services/Reports/AiInsightsWriter.php` (product-aware gate/payload/prompt),
`app/Console/Commands/GenerateDailyAiSummaries.php` (union of active shops).

**Tests:** `tests/Feature/ReportsAggregatorHuntTest.php` (new),
`tests/Feature/AiInsightsWriterTest.php` (extend or new),
`tests/Feature/GenerateDailyAiSummariesTest.php` (new or extend).
