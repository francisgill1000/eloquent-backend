# AI Insights — Design Spec

**Date:** 2026-07-09
**Status:** Approved, pending implementation
**Deploy target:** STAGING only (never prod)

## Goal

An AI-generated, plain-language performance summary for a shop's selected date
range, shown as a card at the top of the Insights page and available by voice
through the owner assistant. A short narrative — not charts:

- 2–3 sentence summary
- 2–3 notable patterns
- 1–2 concrete recommendations

Reuses existing infrastructure only. No new AI integration; no changes to the
internals of `ClaudeClient` or `ReportsAggregator`.

## Confirmed decisions

- **Cache TTL:** 24 hours per `shop_id + from + to`. The Refresh button forces a
  fresh generation.
- **Low-data gate:** fewer than 5 scheduled bookings in the selected period →
  a fixed "not enough data yet" state; no Claude call.
- **JSON strategy:** prompt the model for strict JSON and parse/validate the
  result. `ClaudeClient` is reused as-is (no structured-output changes to the
  shared WhatsApp-bot client).
- **Model:** `config('services.anthropic.model')` (env `CLAUDE_MODEL`), via the
  existing `ClaudeClient` — no hardcoded model id.

## Backend

### `App\Services\Reports\AiInsightsWriter`

New focused service. Single public method:

```php
public function summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh = false): array
```

Returns a validated array:

```php
[
  'state'           => 'ok' | 'low_data' | 'error',
  'summary'         => string,        // 2–3 sentences ('' when not ok)
  'patterns'        => string[],      // 2–3 items ([] when not ok)
  'recommendations' => string[],      // 1–2 items ([] when not ok)
  'message'         => string,        // human line for low_data / error states
  'generated_at'    => string,        // ISO 8601
  'cached'          => bool,
]
```

Flow:

1. **Cache read** — key `ai_insights:{shopId}:{from}:{to}` (dates as `Y-m-d`),
   24h TTL, via the `Cache` facade (default store, consistent with existing
   services that use `Cache::`). `forceRefresh` skips the read; a successful
   generation still writes the cache.
2. **Metrics** — `ReportsAggregator::insightsSummary()` and `revenueSummary()`
   for the **selected** period and the **previous equal-length** period. The
   previous period is computed the same way `Insights.tsx` does:
   `prevTo = from - 1 day`, `prevFrom = prevTo - (lengthDays - 1)`.
3. **Low-data gate** — if selected-period `bookings.scheduled < 5`, return
   `state: low_data` with a fixed `message`. **No Claude call. Not cached** — so
   real data arriving later is not masked by a stale "not enough data" entry.
4. **Payload** — build a compact, numbers-only structure (headline KPIs with
   deltas vs the previous period, completion/cancellation/no-show rates,
   new-vs-returning customers + repeat rate, review average/count, top services).
   Pass computed numbers only — never raw booking rows.
5. **Claude call** — `ClaudeClient::reply($system, $history)`. The system prompt:
   only use the provided numbers; never invent figures or claim trends the data
   doesn't show; write for a non-technical service-business owner; return **only**
   a JSON object with keys `summary` (string), `patterns` (array of strings),
   `recommendations` (array of strings).
6. **Parse/validate** — extract the JSON object from the reply, decode, validate
   that the three keys exist with correct types, coerce to strings, and clamp
   lengths (patterns ≤ 3, recommendations ≤ 2). On decode failure, missing keys,
   wrong types, or a thrown exception → `state: error` with a friendly `message`.
   **Not cached.**
7. On success, cache the `ok` payload (with `cached: false` on first return) and
   return it. Cache hits return with `cached: true`.

Multi-tenant: every metrics call is scoped by `shopId`; nothing crosses shops.

### Endpoint: `GET /shop/reports/ai-summary`

- Added to `ReportsController` beside the other `/shop/reports/*` actions, and to
  `routes/api.php` next to the existing reports routes (~line 98).
- Uses the existing `ReportsController::validated()` helper → identical
  `shop_id` / `from` / `to` validation and tenant scoping.
- Extra optional `refresh` boolean (`refresh=1`) → `forceRefresh`.
- Returns the writer's array as JSON.

## Voice

### `OwnerAssistantTools`

- New **read** tool `get_ai_summary` with the standard `period` enum
  (`today | yesterday | this_week | this_month | last_month | this_year`),
  resolved via `PeriodResolver` exactly like the sibling read tools.
- Wired into `defs()` (schema) and `execute()` (dispatch → `AiInsightsWriter`).
- Injected `AiInsightsWriter` dependency alongside the existing
  `ReportsAggregator` in the constructor.
- Returns the same writer output so "give me my AI summary" / "how are we doing"
  works by voice. Strictly `$shop`-scoped like every other tool.
- Registered through the existing `AssistantToolRegistry` (no registry change
  needed — `OwnerAssistantTools` is already a registered module; the new tool
  rides its `defs()`/`handles()`/`run()`).

## Frontend

### `admin/src/pages/Insights.tsx`

- New `AiInsightsCard` rendered at the **top** of the report, above the KPI hero
  row, on-brand dark mint-glass matching the existing `.ins-card` styling.
- States:
  - **loading** — skeleton block.
  - **loaded** (`ok`) — summary paragraph, a "Patterns" list, and a
    "Recommendations" list.
  - **refreshing** — inline spinner in the header; keeps the previous content
    visible.
  - **low-data** — friendly empty state using the writer's `message`.
  - **error** — short error line with a Retry affordance.
- **Refresh** button in the card header → fetch with `refresh=1`.
- Refetches when the selected date range changes, reusing the page's existing
  range state (own `useEffect`/callback, independent of the charts fetch).

### `admin/src/lib/aiInsights.ts`

New typed client mirroring `insights.ts`:

```ts
export type AiInsights = {
  state: 'ok' | 'low_data' | 'error';
  summary: string;
  patterns: string[];
  recommendations: string[];
  message: string;
  generated_at: string;
  cached: boolean;
};

export async function getAiInsights(
  shopId: number, from: string, to: string, refresh = false
): Promise<AiInsights>;
```

Any styling additions go in the existing `insights.css` under an `ins-ai-*`
namespace so the card reads as part of the same system.

## Testing (TDD)

Run on the droplet (php8.4) against a **scratch DB** — never local, never prod.
Anthropic calls are faked with `Http::fake` / `Http::fakeSequence`
(`api.anthropic.com/v1/messages`), the pattern already used across the assistant
tests.

`AiInsightsWriter` (feature tests):

- Happy path: valid model JSON → `state: ok` with the validated shape;
  `patterns`/`recommendations` clamped.
- Malformed JSON / missing keys / wrong types → `state: error`, no cache write.
- Low-data (<5 scheduled bookings) → `state: low_data`, **zero** HTTP calls
  (assert via `Http::assertNothingSent` or request count).
- Cross-shop isolation: shop A's summary never reflects shop B's bookings.
- Cache: second call within TTL makes no new HTTP call; `forceRefresh` bypasses
  the cache and does call again.

Endpoint (`GET /shop/reports/ai-summary`):

- Validation of `shop_id`/`from`/`to`; tenant scoping; `refresh` param honoured.

Voice (`get_ai_summary`):

- Returns the summary and is shop-scoped; appears in `defs()`; routed by
  `execute()`.

## Scope guard

- No new AI integration; reuse `ClaudeClient` and `ReportsAggregator` unchanged.
- No changes to `ClaudeClient` internals (no structured-output additions).
- Keep files focused; follow existing patterns.
- Deploy to STAGING only. Do not touch prod.
