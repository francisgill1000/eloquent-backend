# Period-aware AI summaries + history

**Date:** 2026-07-12
**Status:** Approved design, pending implementation plan

## Problem

The AI summary only ever shows one thing: the latest "30 days ending yesterday"
rolling summary. Past summaries ARE persisted (one `ai_summaries` row per shop per
day) but nothing surfaces them, and there is no way to view a weekly, monthly, or
custom-range summary. The owner wants to browse history and see week/month/custom
summaries.

## Goal

Let the owner pick a period — **30-day (default), Weekly, Monthly, or Custom
range** — and get an AI summary generated **fresh from that period's real metrics**
(vs. the previous equal-length period), and browse **past** weekly/monthly
summaries. Reuse the existing writer/engine; keep everything grounded in numbers.

## Non-goals

- No "roll-up of daily summaries" (rejected in favour of fresh-from-metrics).
- No change to the product-aware gating (Hunt-priority + bookings fallback) already
  in `AiInsightsWriter`.
- Custom ranges are generate-on-demand (persisted+cached) but NOT surfaced in a
  browsable history list.

## Architecture

### 1. Data model — `ai_summaries` gains a period type

Migration:
- Add `period_type` string column, default `'rolling30'` (existing rows backfill to
  `rolling30`).
- Drop the unique `(shop_id, summary_date)`; add unique
  `(shop_id, period_type, period_from, period_to)`.
- Keep `summary_date` as the generation date (informational).
- Portable on sqlite (tests) + pgsql (prod): `dropUnique`/`unique` map to
  DROP/CREATE INDEX; the `->after()` on the column is a no-op on sqlite.

`period_type` values: `rolling30` | `week` | `month` | `custom`.

`AiSummary` model: add `period_type` to `$fillable`.

### 2. Writer — period-aware

`AiInsightsWriter::summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh = false, string $periodType = 'custom'): array`

- **Cache key** includes the type: `ai_insights:{periodType}:{shopId}:{from}:{to}`.
- **Retrieval** (non-forceRefresh): check cache, then `storedFor($shopId,
  $periodType, $from, $to)` — an EXACT `(shop_id, period_type, period_from,
  period_to)` match. For `rolling30` ONLY, if the exact row is missing, fall back to
  `latestStored($shopId, 'rolling30')` (preserves today's instant morning load and
  tolerates a ±1 day tz boundary, exactly as today). Week/month/custom require an
  exact match, else generate.
- **Generation** unchanged (product-aware gate/payload/prompt). On success, persist
  via `persist($shopId, $from, $to, $parsed, $periodType)` — `updateOrCreate` keyed
  on `(shop_id, period_type, period_from, period_to)`, `summary_date = today`.
- `recentSummaries()` (variety context) stays scoped to the same shop; keep it
  filtering the `rolling30` type so daily variety context is unchanged.

### 3. Endpoints (`ReportsController`)

- **Extend** `GET /shop/reports/ai-summary` to accept `period` (`rolling30` | `week`
  | `month` | `custom`, default `custom`), validated against the allowed set, and
  pass it to `summary(..., $period)`. `from`/`to`/`shop_id`/`refresh` unchanged (the
  frontend computes `from`/`to` for the chosen period).
- **Add** `GET /shop/reports/ai-summaries` — history list. Params: `shop_id`
  (required), `period_type` (required, in the allowed set), `limit` (default 12,
  max 60), `page` (default 1). Returns rows for that shop+type ordered by
  `period_from` DESC: `{ period_from, period_to, summary, patterns,
  recommendations, generated_at }` + `has_more`. New method `aiSummaryHistory`.
- Both live under the existing `/shop/reports/*` block (same pre-existing auth
  posture as the other report routes — not changed here; see the standing open
  decision in [[todo-ai-insights-dashboard]]).

Frontend lib `admin/src/lib/aiInsights.ts`: add `period` to `getAiInsights`, and a
new `getAiSummaryHistory(shopId, periodType, page?)`.

### 4. Scheduled weekly/monthly generation

Extract the shared shop-iteration + active-shop union into a trait
`App\Console\Commands\Concerns\GeneratesShopSummaries`:
- `activeShopIds(string $fromDate): array` (the bookings-OR-Hunt-activity union,
  moved verbatim out of `GenerateDailyAiSummaries`).
- `runFor(AiInsightsWriter $writer, array $shopIds, Carbon $from, Carbon $to,
  string $periodType): array{ok:int, skipped:int, failed:int}`.

- `GenerateDailyAiSummaries` (`ai:daily-summaries`) refactored to use the trait;
  behaviour identical (window = 30 days ending yesterday, `periodType='rolling30'`).
- New `GeneratePeriodAiSummaries` (`ai:period-summaries {--period=week|month}`):
  - `week`: last complete ISO week — `$from = now()->subWeek()->startOfWeek(Monday)`,
    `$to = now()->subWeek()->endOfWeek(Sunday)`, `periodType='week'`.
  - `month`: last complete calendar month — `$from =
    now()->subMonthNoOverflow()->startOfMonth()`, `$to =
    now()->subMonthNoOverflow()->endOfMonth()`, `periodType='month'`.
  - `activeShopIds($from->toDateString())` bounds the run to shops active in that
    window; the writer's per-product gate still skips too-quiet shops.
- Scheduler (`routes/console.php`): add
  `Schedule::command('ai:period-summaries --period=week')->weeklyOn(1, '03:30')`
  and `Schedule::command('ai:period-summaries --period=month')->monthlyOn(1, '04:00')`,
  each `->withoutOverlapping()->onOneServer()`.

### 5. Frontend — period selector + history (`admin/src/pages/AiSummary.tsx`)

- A segmented selector: **30-day · Weekly · Monthly · Custom**.
  - **30-day**: current behaviour (`from`=yesterday-29, `to`=yesterday,
    `period=rolling30`).
  - **Weekly**: default to the current week (`startOfWeek(Mon)`→today,
    `period=week`); show a **history list** of past weeks (from the history
    endpoint) — tapping one loads that week's summary (its stored `from`/`to`).
  - **Monthly**: default to the current month (`startOfMonth`→today,
    `period=month`); history list of past months.
  - **Custom**: two date inputs (`from`/`to`) + a "Generate" action
    (`period=custom`).
- The existing `AiInsightsCard` (overview + patterns + recommendations + Listen
  TTS + loading/low-data/error states) is reused unchanged for whichever period is
  active; the card sub-title reflects the selected period ("this week", "March
  2026", "1–15 Apr", etc.).
- History items show their period label and are read-only reads of stored rows
  (no Claude call). Selecting the current (in-progress) week/month generates on
  demand; selecting a past one served from the store is instant.

## Testing

- **Migration/model**: `period_type` column present, default `rolling30`, the new
  unique enforced (a second row with the same shop+type+from+to upserts, not
  duplicates); existing rows readable.
- **Writer**: `summary(..., 'week')` persists a row with `period_type='week'` and
  the right window; a repeat request is served from the store (no second Claude
  call); `rolling30` still uses the `latestStored` fallback; the cache key is
  type-scoped (a `week` and a `rolling30` for the same from/to don't collide).
- **History endpoint**: returns only the requested `period_type`, newest first,
  paginated with `has_more`; tenant-scoped by `shop_id`.
- **Commands**: `ai:period-summaries --period=week` generates+persists a `week` row
  for an active shop over the last-week window and skips a shop with no activity in
  that window; `--period=month` likewise; `ai:daily-summaries` behaviour unchanged
  after the trait refactor.
- **Frontend**: `tsc` clean; a Vitest test for the selector — switching period
  refetches with the right `period`/`from`/`to`; the history list renders stored
  periods and selecting one loads it. (Frontend tests run locally: `npx tsc
  --noEmit` + `npx vitest run` in `admin/`.)
- Backend tests run on the droplet harness (sqlite `:memory:`), never local/prod.

## Files

**New:** migration `..._add_period_type_to_ai_summaries.php`,
`app/Console/Commands/Concerns/GeneratesShopSummaries.php`,
`app/Console/Commands/GeneratePeriodAiSummaries.php`,
`tests/Feature/AiSummaryHistoryTest.php`,
`tests/Feature/GeneratePeriodAiSummariesTest.php`,
`admin/src/pages/AiSummary.periods.test.tsx`.

**Modified:** `app/Models/AiSummary.php`, `app/Services/Reports/AiInsightsWriter.php`,
`app/Http/Controllers/ReportsController.php`, `routes/api.php`,
`app/Console/Commands/GenerateDailyAiSummaries.php`, `routes/console.php`,
`admin/src/lib/aiInsights.ts`, `admin/src/pages/AiSummary.tsx`,
`admin/src/styles/insights.css`, and the existing
`tests/Feature/AiInsightsWriterTest.php` / `GenerateDailyAiSummariesTest.php`
where the writer signature / trait refactor touches them.
