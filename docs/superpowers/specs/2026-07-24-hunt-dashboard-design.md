# Business Hunt Dashboard — Design

**Date:** 2026-07-24
**Status:** Approved, ready for planning

## Problem

Business Hunt has no at-a-glance screen. A manager who wants to know how the
pipeline is doing has to read the Leads list and count.

The numbers already exist. `ReportsAggregator::huntSummary()` computes new
leads, pipeline by status, wins, won value, MRR, credits used and searches
run; `huntByAgent()` (added 2026-07-24 with lead assignment) computes a
per-agent leaderboard. **Neither is exposed over HTTP.** Their only consumer is
`AiInsightsWriter`, the nightly job that turns them into prose. So the data is
computed daily and nobody can look at it.

By contrast the Bookings module has `/insights` — KPI row, trend chart,
donuts, rate bars — a page Hunt has no equivalent of.

## Scope

Business Hunt only. Bookings' `/insights` page is untouched in behaviour
(though its chart components get extracted for reuse — see §4).

## Audience

Anyone holding `leads.view`. No new permission.

This works because the agent-scoping built for lead assignment already applies:
`huntSummary` filters through `agentLeadFilter()`, and `huntByAgent` returns
`[]` for a caller who cannot see all leads. So the same page serves two
purposes with no branching — a manager sees the whole shop plus the
leaderboard; an agent sees their own numbers and no leaderboard, which makes it
their personal scoreboard.

`Rbac` treats a null acting user (console, queue, legacy untagged token) as
owner-equivalent, so the nightly AI job keeps seeing everything.

---

## 1. Backend

### 1.1 Endpoint

```
GET /shop/reports/hunt?from=YYYY-MM-DD&to=YYYY-MM-DD
```

Handled by a new `ReportsController::hunt()`. It reuses the existing
`validated()` helper, so the tenant is **the authenticated shop, never a
request-supplied `shop_id`**, and an inverted range is normalised.

It needs its own route group. The existing reports group requires
`module:bookings` + `can.perm:reports.view`, which would lock out a Hunt-only
shop:

```php
Route::middleware(['auth:sanctum', 'rbac.context', 'module:leads', 'can.perm:leads.view'])
    ->group(function () {
        Route::get('/shop/reports/hunt', [ReportsController::class, 'hunt']);
    });
```

`rbac.context` is required — without it `current_shop_user()` is null and every
agent would be treated as owner-equivalent, seeing the whole shop.

### 1.2 Response

One payload, one round-trip:

```jsonc
{
  "range":   { "from": "2026-06-25", "to": "2026-07-24" },
  "summary": { /* huntSummary() verbatim */ },
  "daily":   [ { "date": "2026-06-25", "leads": 4, "won": 1, "won_value": 1800.0 } ],
  "attention": {
    "followups_overdue": 6,
    "followups_today":   3,
    "stale":             11,
    "unassigned":        24
  },
  "agents":  [ { "id": 3, "name": "Sara", "leads": 41, "won": 2, "won_value": 3600.0 } ],
  "credits": { "balance": 120, "used": 18, "searches": 18 }
}
```

`credits.used` and `credits.searches` are lifted from `summary` for convenience;
`balance` comes from `HuntCreditService::balance()`.

### 1.3 `ReportsAggregator::huntDaily(int $shopId, Carbon $from, Carbon $to): array`

Zero-filled per-day series so the chart gets a continuous line. Capped at 366
days, matching `dailyBreakdown()`.

- `leads` — leads whose `created_at` falls in the day.
- `won` / `won_value` — leads whose current status is `won` and whose
  `deal_won_at` falls in the day. Same attribution rule as `wonValueTotals()`.

**Bucketing happens in PHP, not SQL.** Grouping a timestamp by date differs
between sqlite (tests) and pgsql (prod); `huntSummary` already avoids that for
the same reason. Volume is bounded — hundreds of leads per shop.

### 1.4 `ReportsAggregator::huntAttention(int $shopId): array`

A **current snapshot**, not period-bound — "what needs chasing right now" does
not depend on the date filter. The page will label it so.

| Key | Meaning |
|---|---|
| `followups_overdue` | `next_followup_at` before today, status ∉ {won, pass} |
| `followups_today` | `next_followup_at` within today, status ∉ {won, pass} |
| `stale` | status ∈ {sent, followup, replied, demo} **and** (`last_contacted_at` null or older than 14 days) |
| `unassigned` | `assigned_to_id` is null |

`stale` deliberately excludes status `new`. A freshly imported lead is
uncontacted by definition; including it would make the number the size of the
import (328 leads on prod) and therefore useless. Stale means "you started
working it and dropped it".

`unassigned` needs no manager special-case: `AssignedLeadScope` rewrites an
agent's query to `assigned_to_id = <them>`, so `whereNull('assigned_to_id')`
naturally yields 0. A test asserts this rather than leaving it implied.

### 1.5 Both new methods use Eloquent, not the raw query builder

`huntSummary` and `huntByAgent` use `DB::table('leads')` and must therefore
filter by agent explicitly (`agentLeadFilter()`) — a documented trap from the
lead-assignment work.

The two new methods use `Lead::query()` instead, so `AssignedLeadScope` applies
automatically and correctly. This is deliberate, and the class comment is
updated to say which methods take which route and why. `huntAttention` in
particular *must* be Eloquent, because it shares its filter definitions with
`LeadController::index` (§1.6).

### 1.6 Shared filter definitions — three query scopes on `Lead`

The dashboard's attention counts and the Leads page's filtered lists must agree
exactly, or a chip reading "6 overdue" opens a list of 9. One definition each:

- `scopeFollowupOverdue()`
- `scopeFollowupToday()`
- `scopeStale()`

Used by both `huntAttention()` and `LeadController::index`.

`LeadController::index` gains `followups=overdue`, `followups=today` and
`stale=1` query parameters so each chip can link to precisely its own set.

**Known inconsistency, left alone on purpose:** the pre-existing
`followups=due` filter (`next_followup_at <= now()`) does not exclude won/pass
leads, while the new scopes do. Changing `due` would alter the behaviour of the
"Due" toggle already on the Leads page, which is out of scope here. The new
parameters are additive.

### 1.7 `dealTotal()` helper

The rule "recurring deals are `amount × term`, one-offs are `amount`, and a
recurring deal with no term contributes nothing" is currently written out twice
— in `wonValueTotals()` and in `huntByAgent()`. `huntDaily()` would make three.
It gets extracted to one private helper and the two existing call sites move
onto it. Behaviour is unchanged; the existing tests prove that.

---

## 2. Frontend — `/hunt-insights`

Route registered under the existing `ModuleGuard module="leads"` +
`RequirePerm perm="leads.view"` block, alongside `/leads`.

**Sections, top to bottom:**

1. **Range filter** — 7d / 30d / 90d / this month / last month / this year /
   custom, identical to `/insights`. Fetches the previous equal-length period
   too, for deltas.

2. **KPI row** — New leads · Deals won · Won value · MRR won. All four are
   period-attributed and already computed; each shows a delta vs the previous
   period.

   *Conversion rate is deliberately absent.* Wins in a period mostly come from
   leads created before it, so any ratio of period-wins to period-leads is
   misleading. The funnel card carries the honest version instead (item 5).

3. **Needs attention** — four chips: Overdue · Due today · Stale 14d+ ·
   Unassigned. Each links into `/leads` pre-filtered via the §1.6 parameters.
   Labelled "right now" to distinguish it from the date-filtered sections.
   The Unassigned chip renders only when the count is non-zero, so agents
   (always 0) never see it.

4. **Trend** — full width, two series: leads in and deals won per day.

5. **Funnel** — the 7 fixed stages as horizontal bars with count and % of
   total, current snapshot. Carries the one honest ratio available: of leads
   that reached a decision, `won / (won + pass)`.

6. **Deal mix** — donut of one-off vs recurring won value.

7. **Agent leaderboard** — Agent · Leads held · Won · Won value, best value
   first. Renders only when `agents` is non-empty, which by construction means
   manager-and-there-are-agents. This is the payoff screen for lead assignment.

8. **Credits** — balance, used this period, searches run, link to `/leads/credits`.

Every card degrades to an empty state rather than a broken chart when a shop
has no data yet — a new Hunt shop opens this page on day one.

### 2.1 Navigation

- **Desktop rail** (`DesktopSidebar.BASE_NAV`): a "Hunt Stats" item, `modules:
  ['leads']`, `perm: 'leads.view'`.
- **Settings list** (`lib/nav.ts`): the same destination marked `shortcut:
  true` — the existing pattern for entries that duplicate a top-level item
  (Business Hunt, Customers). This is how mobile reaches it, since there is no
  bottom-tab slot free, and `shortcut: true` keeps it from making an otherwise
  empty Settings menu appear.

### 2.2 Leads page reads its filters from the URL

`PipelinePane` currently initialises every filter to a constant. It gains
`useSearchParams` seeding for `status`, `followups`, `stale` and `assigned_to`,
so the attention chips land on a correctly filtered list. Filters remain local
state after mount — this is deep-linking, not two-way URL binding.

---

## 3. Chart components

`Insights.tsx` (419 lines) holds `TrendChart`, `Donut`, `RateBars`, `Kpi`,
`Delta`, `ChartCard` and `EmptyState` as file-local components, plus the date
preset helpers. The Hunt page needs all of them.

They move to `admin/src/components/charts/` and `admin/src/lib/dateRange.ts`.
`TrendChart` is generalised from `InsightsDaily[]` to N named series of
`{date, value}` — `/insights` passes one series, `/hunt-insights` passes two.

### 3.1 Risk: `Insights.tsx` has no tests

Extracting from an untested 419-line file is a blind refactor.

**Mitigation, in this order:**
1. Write a characterization test for `/insights` against a mocked payload —
   KPIs, chart presence, donut legend values, empty states.
2. Watch it pass on the current code.
3. Extract.
4. Watch it still pass.

The extracted components then get their own unit tests. Net test coverage rises;
the refactor is provably behaviour-preserving rather than hopefully so.

---

## 4. Testing

**Backend — `tests/Feature/HuntDashboardTest.php`**

- `huntDaily` zero-fills gaps; buckets by the correct date column; won value
  follows the recurring × term rule; 366-day cap holds.
- `huntDaily` and `huntAttention` are tenant-scoped (a second shop's leads
  never appear).
- `huntAttention` counts each bucket correctly; `stale` excludes `new`;
  followup buckets exclude won/pass.
- An agent sees only their own numbers, and `unassigned` is 0 for them.
- Endpoint: 401 unauthenticated; 403 without `leads.view`; reachable by a
  Hunt-only shop; a request-supplied `shop_id` is ignored; `agents` is `[]` for
  an agent and populated for a manager.
- `LeadController::index` honours `followups=overdue`, `followups=today` and
  `stale=1`, and their counts match `huntAttention` exactly for the same
  fixture — the guarantee that a chip's number matches the list it opens.

**Frontend**

- Characterization test for `/insights` (before the refactor).
- Unit tests for each extracted chart component.
- `HuntInsights.test.tsx` — KPIs render from a mocked payload; leaderboard
  hidden when `agents` is empty and shown when populated; attention chips carry
  the right hrefs; unassigned chip hidden at 0; empty state for a shop with no
  leads.
- `DesktopSidebar` and `nav.ts` tests extended for the new item's module +
  permission gating.

**Existing suites must stay green** — 596 backend, 226 frontend.

## 5. Rollout

Local → staging → prod, the standing rule. PHP tests run on the droplet
(`php8.4`), never locally, and never against the prod database. Admin frontend
ships via `admin/deploy.ps1`.

No migration. No schema change. No change to any existing endpoint's response.
The only behavioural change outside the new page is three additive query
parameters on `GET /shop/leads`.

## 6. Files

**Create**
- `admin/src/pages/HuntInsights.tsx`
- `admin/src/pages/HuntInsights.test.tsx`
- `admin/src/lib/huntInsights.ts`
- `admin/src/lib/dateRange.ts`
- `admin/src/components/charts/*.tsx` (+ tests)
- `admin/src/styles/hunt-insights.css`
- `tests/Feature/HuntDashboardTest.php`
- `admin/src/pages/Insights.test.tsx` (characterization)

**Modify**
- `app/Services/Reports/ReportsAggregator.php` — `huntDaily`, `huntAttention`,
  `dealTotal`, class comment
- `app/Models/Lead.php` — three query scopes
- `app/Http/Controllers/ReportsController.php` — `hunt()`
- `app/Http/Controllers/LeadController.php` — three index filters
- `routes/api.php` — one route group
- `admin/src/App.tsx` — one route
- `admin/src/layout/DesktopSidebar.tsx` — one nav item
- `admin/src/lib/nav.ts` — one settings entry
- `admin/src/lib/leads.ts` — three filter params
- `admin/src/pages/Leads.tsx` — URL-seeded filters
- `admin/src/pages/Insights.tsx` — import extracted components
