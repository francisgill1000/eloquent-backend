# Business Hunt Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Business Hunt an at-a-glance dashboard — KPIs, what-needs-chasing, trend, funnel, deal mix, agent leaderboard and credits — served by one new endpoint.

**Architecture:** The numbers are already computed by `ReportsAggregator::huntSummary()` and `huntByAgent()` but have no HTTP surface. Add two aggregate methods (`huntDaily`, `huntAttention`), expose all four through `GET /shop/reports/hunt`, and render them on a new `/hunt-insights` page built from chart components extracted out of the existing `Insights.tsx`. Agent scoping comes free: `huntSummary` filters via `agentLeadFilter()`, `huntByAgent` returns `[]` for agents, and the new Eloquent-based methods inherit `AssignedLeadScope`.

**Tech Stack:** Laravel 12 / PHP 8.4, PostgreSQL (prod/staging) + SQLite `:memory:` (tests), Sanctum, spatie/laravel-permission (teams mode), React 18 + TypeScript + Vite + Vitest + Testing Library.

## Global Constraints

- **No feature branches.** Work directly on `main`; commit and push to `main` only.
- **PHP tests run on the droplet**, never locally (local PHP is broken). Use `scratchpad/synctest.sh` (§Test harness below). **Never run tests against the prod database.**
- **Deploy order is local → staging → prod.** Nothing destructive on prod unless explicitly asked.
- **Admin frontend deploys via `admin/deploy.ps1`**, not manual build/scp.
- **Tenant is always the token's shop**, never a request-supplied `shop_id`.
- **No migration, no schema change** in this plan. The only change to an existing endpoint is three *additive* query parameters on `GET /shop/leads`.
- **Existing suites must stay green:** 596 backend tests, 226 frontend tests.
- **Static routes are declared before `{lead}` routes** in `routes/api.php` — order matters.
- Multi-tenant rule: never bake one shop's name/brand into a default.

### Test harness

Backend tests run on the droplet against an isolated checkout at `/root/testrun`:

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/16e16f96-290e-43dd-bb32-2c46781a9fe4/scratchpad/synctest.sh" --filter=SomeTest
```

That script tars `app tests database routes` to the droplet, runs `php artisan optimize:clear` (the critical step — a cached config makes phpunit's sqlite `:memory:` lose to the real DB), then `php artisan test`. Pass any `artisan test` argument through.

Frontend tests run locally:

```bash
cd admin && npx vitest run
```

---

## File Structure

**Backend**

| File | Responsibility |
|---|---|
| `app/Services/Reports/ReportsAggregator.php` | + `dealTotal()` (private), `huntDaily()`, `huntAttention()` |
| `app/Models/Lead.php` | + `WORKING_STATUSES`, `STALE_DAYS`, three query scopes shared by the aggregator and the controller |
| `app/Http/Controllers/ReportsController.php` | + `hunt()` — assembles the one payload |
| `app/Http/Controllers/LeadController.php` | + `followups=overdue\|today` and `stale=1` filters on `index` |
| `routes/api.php` | + one route group for the Hunt report |
| `tests/Feature/HuntDashboardTest.php` | Everything above |

**Frontend**

| File | Responsibility |
|---|---|
| `admin/src/lib/dateRange.ts` | Date presets + formatting + `pctChange`, shared by both report pages |
| `admin/src/components/charts/ChartCard.tsx` | Card shell (icon, title, subtitle) |
| `admin/src/components/charts/Kpi.tsx` | KPI tile + `Delta` |
| `admin/src/components/charts/EmptyState.tsx` | "No data yet" placeholder |
| `admin/src/components/charts/Donut.tsx` | Donut + legend |
| `admin/src/components/charts/RateBars.tsx` | Labelled percentage bars |
| `admin/src/components/charts/TrendChart.tsx` | N-series area/line chart with hover |
| `admin/src/components/charts/RangeFilter.tsx` | Preset segmented control + custom dates |
| `admin/src/lib/huntInsights.ts` | API client + types for `GET /shop/reports/hunt` |
| `admin/src/pages/HuntInsights.tsx` | The page |
| `admin/src/styles/hunt-insights.css` | Hunt-only bits (attention chips, funnel bars, leaderboard) |
| `admin/src/pages/Insights.tsx` | Rewired onto the extracted components; behaviour unchanged |

---

## Task 1: `dealTotal()` — one definition of what a won deal is worth

**Files:**
- Modify: `app/Services/Reports/ReportsAggregator.php` (`wonValueTotals`, `huntByAgent`)
- Test: `tests/Feature/ReportsAggregatorHuntTest.php`

**Interfaces:**
- Produces: `ReportsAggregator::dealTotal(mixed $amount, mixed $type, mixed $term): ?float` — private. Returns the deal's total value, or `null` when it cannot be valued (no amount, or a recurring deal with no term).

This is a refactor: the rule is currently written out twice and Task 2 would make it three times. There is no new behaviour to drive with a red test, so instead we **pin the edge case first** with a characterization test, then refactor under it.

- [ ] **Step 1: Write the characterization test**

Append to `tests/Feature/ReportsAggregatorHuntTest.php`, inside the class:

```php
    public function test_won_value_skips_deals_that_cannot_be_valued(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        // Valued: one-off 500.
        Lead::factory()->create([
            'shop_id' => $shop->id, 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off',
            'deal_won_at' => now(),
        ]);
        // Valued: recurring 100 × 6 = 600, contributes 100 to MRR.
        Lead::factory()->create([
            'shop_id' => $shop->id, 'status' => 'won',
            'deal_amount' => 100, 'deal_type' => 'recurring', 'deal_term_months' => 6,
            'deal_won_at' => now(),
        ]);
        // Not valuable: recurring with no term — no computable total.
        Lead::factory()->create([
            'shop_id' => $shop->id, 'status' => 'won',
            'deal_amount' => 900, 'deal_type' => 'recurring', 'deal_term_months' => null,
            'deal_won_at' => now(),
        ]);
        // Not valuable: a win logged with no amount at all.
        Lead::factory()->create([
            'shop_id' => $shop->id, 'status' => 'won',
            'deal_amount' => null, 'deal_type' => null,
            'deal_won_at' => now(),
        ]);

        $out = app(ReportsAggregator::class)->wonValueTotals($shop->id);

        $this->assertSame(1100.0, $out['won_value']);
        $this->assertSame(500.0, $out['won_value_one_off']);
        $this->assertSame(600.0, $out['won_value_recurring']);
        $this->assertSame(100.0, $out['mrr_won']);
        // won_count counts only deals that carried a computable value.
        $this->assertSame(2, $out['won_count']);
    }
```

- [ ] **Step 2: Run it against the CURRENT code**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/16e16f96-290e-43dd-bb32-2c46781a9fe4/scratchpad/synctest.sh" --filter=test_won_value_skips_deals_that_cannot_be_valued
```

Expected: **PASS**. This is the point — it documents behaviour that already exists, so the refactor has something to break.

- [ ] **Step 3: Add the helper**

In `app/Services/Reports/ReportsAggregator.php`, add immediately above `wonValueTotals()`:

```php
    /**
     * What a won deal is worth in total: a one-off is its amount, a recurring
     * deal is amount × term. Returns null when the row cannot be valued — no
     * amount, or a recurring deal with no term — so callers can tell "worth
     * nothing" from "not computable" and skip it consistently.
     *
     * Params are mixed because callers reach this both through Eloquent and
     * through the raw query builder, which hands back numeric strings on pgsql.
     */
    private function dealTotal(mixed $amount, mixed $type, mixed $term): ?float
    {
        $amount = (float) ($amount ?? 0);
        if ($amount <= 0) {
            return null;
        }

        if ($type === 'recurring') {
            $term = (int) ($term ?? 0);

            return $term > 0 ? $amount * $term : null;
        }

        return $amount;
    }
```

- [ ] **Step 4: Move `wonValueTotals` onto it**

Replace the `foreach ($rows as $d) { ... }` body inside `wonValueTotals()` with:

```php
        foreach ($rows as $d) {
            $total = $this->dealTotal($d->deal_amount, $d->deal_type, $d->deal_term_months);
            if ($total === null) {
                continue;
            }
            if ($d->deal_type === 'recurring') {
                $recurring += $total;
                $mrr += (float) $d->deal_amount;
            } else {
                $oneOff += $total;
            }
            $wonValue += $total;
            $count++;
        }
```

- [ ] **Step 5: Move `huntByAgent` onto it**

Replace the `foreach ($wonRows as $row) { ... }` body inside `huntByAgent()` with:

```php
        foreach ($wonRows as $row) {
            $id = (int) $row->assigned_to_id;
            $wonCount[$id] = ($wonCount[$id] ?? 0) + 1;

            $total = $this->dealTotal($row->deal_amount, $row->deal_type, $row->deal_term_months);
            if ($total === null) {
                continue;
            }
            $wonValue[$id] = ($wonValue[$id] ?? 0) + $total;
        }
```

Note the ordering: `$wonCount` increments **before** the valuation check, exactly as before — a win with no amount still counts as a win.

- [ ] **Step 6: Run the Hunt + assignment suites**

```bash
bash ".../scratchpad/synctest.sh" --filter='ReportsAggregatorHuntTest|LeadAssignmentTest'
```

Expected: PASS, all of them, unchanged counts.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Reports/ReportsAggregator.php tests/Feature/ReportsAggregatorHuntTest.php
git commit -m "refactor(reports): single dealTotal() rule for won-deal valuation"
```

---

## Task 2: `huntDaily()` — the per-day series

**Files:**
- Modify: `app/Services/Reports/ReportsAggregator.php`
- Test: `tests/Feature/HuntDashboardTest.php` (create)

**Interfaces:**
- Consumes: `dealTotal()` (Task 1).
- Produces: `ReportsAggregator::huntDaily(int $shopId, Carbon $from, Carbon $to): array` — a zero-filled list of `['date' => 'Y-m-d', 'leads' => int, 'won' => int, 'won_value' => float]`, one entry per day inclusive, capped at 366 entries.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HuntDashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Reports\ReportsAggregator;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Business Hunt dashboard: the two new aggregates, the endpoint that
 * serves them, and the guarantee that an attention count matches the filtered
 * list its chip links to.
 */
class HuntDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** A non-owner user holding exactly $perms. */
    private function agent(Shop $shop, array $perms = ['leads.view', 'leads.manage']): ShopUser
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'R-'.uniqid(), 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions($perms);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);

        return $u;
    }

    private function tokenFor(Shop $shop, ShopUser $user): string
    {
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return $new->plainTextToken;
    }

    private function authJson(string $token, string $method, string $url, array $body = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer $token", 'Accept' => 'application/json'])
            ->json($method, $url, $body);
    }

    public function test_hunt_daily_zero_fills_and_buckets_by_the_right_date(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        // Two leads created on day 1, none on day 2, one on day 3.
        Lead::factory()->count(2)->create(['shop_id' => $shop->id, 'created_at' => '2026-07-01 09:00:00']);
        Lead::factory()->create(['shop_id' => $shop->id, 'created_at' => '2026-07-03 09:00:00']);

        // A win on day 3, created earlier — proves wins bucket by deal_won_at,
        // not created_at.
        Lead::factory()->create([
            'shop_id' => $shop->id, 'status' => 'won',
            'created_at' => '2026-06-01 09:00:00',
            'deal_won_at' => '2026-07-03 15:00:00',
            'deal_amount' => 250, 'deal_type' => 'one_off',
        ]);

        $out = app(ReportsAggregator::class)->huntDaily(
            $shop->id,
            \Carbon\Carbon::parse('2026-07-01'),
            \Carbon\Carbon::parse('2026-07-03'),
        );

        $this->assertCount(3, $out);
        $this->assertSame(
            [
                ['date' => '2026-07-01', 'leads' => 2, 'won' => 0, 'won_value' => 0.0],
                ['date' => '2026-07-02', 'leads' => 0, 'won' => 0, 'won_value' => 0.0],
                ['date' => '2026-07-03', 'leads' => 2, 'won' => 1, 'won_value' => 250.0],
            ],
            $out,
        );
    }

    public function test_hunt_daily_is_tenant_scoped(): void
    {
        $a = Shop::factory()->create(['modules' => ['leads']]);
        $b = Shop::factory()->create(['modules' => ['leads']]);
        Lead::factory()->create(['shop_id' => $b->id, 'created_at' => '2026-07-01 09:00:00']);

        $out = app(ReportsAggregator::class)->huntDaily(
            $a->id,
            \Carbon\Carbon::parse('2026-07-01'),
            \Carbon\Carbon::parse('2026-07-01'),
        );

        $this->assertSame([['date' => '2026-07-01', 'leads' => 0, 'won' => 0, 'won_value' => 0.0]], $out);
    }

    public function test_hunt_daily_shows_an_agent_only_their_own_leads(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $agent = $this->agent($shop, ['leads.view']); // no leads.view_all

        Lead::factory()->create(['shop_id' => $shop->id, 'created_at' => '2026-07-01 09:00:00', 'assigned_to_id' => $agent->id]);
        Lead::factory()->create(['shop_id' => $shop->id, 'created_at' => '2026-07-01 09:00:00']); // someone else's

        \App\Support\CurrentShopUser::set($agent);
        $out = app(ReportsAggregator::class)->huntDaily(
            $shop->id,
            \Carbon\Carbon::parse('2026-07-01'),
            \Carbon\Carbon::parse('2026-07-01'),
        );

        $this->assertSame(1, $out[0]['leads']);
    }

    public function test_hunt_daily_caps_at_366_days(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $out = app(ReportsAggregator::class)->huntDaily(
            $shop->id,
            \Carbon\Carbon::parse('2020-01-01'),
            \Carbon\Carbon::parse('2026-01-01'),
        );

        $this->assertCount(366, $out);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
bash ".../scratchpad/synctest.sh" --filter=HuntDashboardTest
```

Expected: FAIL — `Call to undefined method App\Services\Reports\ReportsAggregator::huntDaily()`.

- [ ] **Step 3: Implement `huntDaily()`**

In `app/Services/Reports/ReportsAggregator.php`, add after `huntByAgent()`:

```php
    /**
     * Per-day Hunt activity across [from, to], inclusive and zero-filled so the
     * dashboard charts a continuous line. Capped at 366 entries, matching
     * dailyBreakdown().
     *
     * Buckets in PHP rather than SQL: grouping a timestamp by date differs
     * between sqlite (tests) and pgsql (prod), the same reason huntSummary
     * aggregates its status changes in PHP.
     *
     * @return array<int, array{date: string, leads: int, won: int, won_value: float}>
     */
    public function huntDaily(int $shopId, Carbon $from, Carbon $to): array
    {
        $created = Lead::where('shop_id', $shopId)
            ->whereBetween('created_at', [$from, $to])
            ->pluck('created_at');

        $wonRows = Lead::where('shop_id', $shopId)
            ->where('status', 'won')
            ->whereNotNull('deal_won_at')
            ->whereBetween('deal_won_at', [$from, $to])
            ->get(['deal_won_at', 'deal_amount', 'deal_type', 'deal_term_months']);

        $leadsBy = [];
        foreach ($created as $at) {
            $key = Carbon::parse($at)->toDateString();
            $leadsBy[$key] = ($leadsBy[$key] ?? 0) + 1;
        }

        $wonBy = [];
        $valueBy = [];
        foreach ($wonRows as $row) {
            $key = Carbon::parse($row->deal_won_at)->toDateString();
            $wonBy[$key] = ($wonBy[$key] ?? 0) + 1;
            $total = $this->dealTotal($row->deal_amount, $row->deal_type, $row->deal_term_months);
            if ($total !== null) {
                $valueBy[$key] = ($valueBy[$key] ?? 0) + $total;
            }
        }

        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $guard = 0;

        while ($cursor->lte($end) && $guard < 366) {
            $key = $cursor->toDateString();
            $out[] = [
                'date'      => $key,
                'leads'     => (int) ($leadsBy[$key] ?? 0),
                'won'       => (int) ($wonBy[$key] ?? 0),
                'won_value' => round((float) ($valueBy[$key] ?? 0), 2),
            ];
            $cursor->addDay();
            $guard++;
        }

        return $out;
    }
```

Note this reads through `Lead::` (Eloquent), not `DB::table('leads')`, so `AssignedLeadScope` narrows it to the acting agent with no explicit filter.

- [ ] **Step 4: Update the class's raw-query note**

The class currently warns that raw reads need an explicit agent filter. Two methods now take the other route, so replace the docblock above `agentLeadFilter()` with:

```php
    /**
     * The agent whose leads the caller is limited to, or null when they see the
     * whole shop.
     *
     * `huntSummary`, `huntByAgent` and `wonValueTotals` read through the raw
     * query builder for portability, so the Lead model's AssignedLeadScope does
     * NOT apply — they must call this and filter explicitly, or an agent would
     * read the whole shop's pipeline and revenue.
     *
     * `huntDaily` and `huntAttention` read through Eloquent instead, so the
     * global scope applies automatically and they do NOT call this.
     */
```

- [ ] **Step 5: Run the test**

```bash
bash ".../scratchpad/synctest.sh" --filter=HuntDashboardTest
```

Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Reports/ReportsAggregator.php tests/Feature/HuntDashboardTest.php
git commit -m "feat(reports): huntDaily() per-day leads & wins series"
```

---

## Task 3: Lead scopes + `huntAttention()`

**Files:**
- Modify: `app/Models/Lead.php`, `app/Services/Reports/ReportsAggregator.php`
- Test: `tests/Feature/HuntDashboardTest.php`

**Interfaces:**
- Produces: `Lead::WORKING_STATUSES` (`['sent','followup','replied','demo']`), `Lead::STALE_DAYS` (`14`), scopes `followupOverdue()`, `followupToday()`, `stale()` — all callable on a `Lead` query builder; and `ReportsAggregator::huntAttention(int $shopId): array` returning `['followups_overdue' => int, 'followups_today' => int, 'stale' => int, 'unassigned' => int]`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/HuntDashboardTest.php`:

```php
    /** Seeds one lead in each attention bucket plus decoys. Returns the shop. */
    private function attentionFixture(): Shop
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $base = ['shop_id' => $shop->id, 'assigned_to_id' => null];

        // Overdue follow-up.
        Lead::factory()->create($base + [
            'status' => 'followup', 'next_followup_at' => now()->subDays(3),
            'last_contacted_at' => now()->subDays(3),
        ]);
        // Due today.
        Lead::factory()->create($base + [
            'status' => 'sent', 'next_followup_at' => now()->setTime(18, 0),
            'last_contacted_at' => now(),
        ]);
        // Decoy: won, with an overdue follow-up date left behind. Not actionable.
        Lead::factory()->create($base + [
            'status' => 'won', 'next_followup_at' => now()->subDays(9),
            'deal_won_at' => now(), 'deal_amount' => 100, 'deal_type' => 'one_off',
        ]);
        // Stale: worked, then dropped for 20 days.
        Lead::factory()->create($base + [
            'status' => 'replied', 'last_contacted_at' => now()->subDays(20),
        ]);
        // Decoy: `new` and never contacted. Not stale — it was never worked.
        Lead::factory()->create($base + ['status' => 'new', 'last_contacted_at' => null]);

        return $shop;
    }

    public function test_hunt_attention_counts_each_bucket(): void
    {
        $shop = $this->attentionFixture();

        $out = app(ReportsAggregator::class)->huntAttention($shop->id);

        $this->assertSame(1, $out['followups_overdue'], 'the won lead must not count');
        $this->assertSame(1, $out['followups_today']);
        $this->assertSame(1, $out['stale'], 'a never-worked `new` lead is not stale');
        $this->assertSame(5, $out['unassigned']);
    }

    public function test_hunt_attention_is_tenant_scoped(): void
    {
        $this->attentionFixture();
        $other = Shop::factory()->create(['modules' => ['leads']]);

        $out = app(ReportsAggregator::class)->huntAttention($other->id);

        $this->assertSame(
            ['followups_overdue' => 0, 'followups_today' => 0, 'stale' => 0, 'unassigned' => 0],
            $out,
        );
    }

    public function test_hunt_attention_shows_an_agent_only_their_own_and_never_unassigned(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $agent = $this->agent($shop, ['leads.view']); // no leads.view_all

        Lead::factory()->create([
            'shop_id' => $shop->id, 'assigned_to_id' => $agent->id,
            'status' => 'followup', 'next_followup_at' => now()->subDay(),
        ]);
        Lead::factory()->create([
            'shop_id' => $shop->id, 'assigned_to_id' => null,
            'status' => 'followup', 'next_followup_at' => now()->subDay(),
        ]);

        \App\Support\CurrentShopUser::set($agent);
        $out = app(ReportsAggregator::class)->huntAttention($shop->id);

        $this->assertSame(1, $out['followups_overdue']);
        // AssignedLeadScope rewrites the query to assigned_to_id = <agent>, so
        // "unassigned" is unreachable for them by construction.
        $this->assertSame(0, $out['unassigned']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
bash ".../scratchpad/synctest.sh" --filter=HuntDashboardTest
```

Expected: FAIL — `Call to undefined method ...::huntAttention()`.

- [ ] **Step 3: Add the constants and scopes to `Lead`**

In `app/Models/Lead.php`, add the constants directly below the existing `STATUSES` constant:

```php
    /** Stages where a lead is actively being worked — past `new`, not yet decided. */
    public const WORKING_STATUSES = ['sent', 'followup', 'replied', 'demo'];

    /** Days without contact before a worked lead counts as having gone cold. */
    public const STALE_DAYS = 14;
```

Then add the three scopes next to the existing `scopeForShop()` (around line 93):

```php
    /**
     * Undecided leads whose follow-up date has already passed.
     *
     * Deliberately broader than the older `followups=due` filter, which
     * restricts to sent/followup/replied and so silently skips a lead sitting
     * at demo stage. Shared with ReportsAggregator::huntAttention so the
     * dashboard's count and the list it links to cannot drift apart.
     */
    public function scopeFollowupOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<', now()->startOfDay())
            ->whereNotIn('status', ['won', 'pass']);
    }

    /** Undecided leads whose follow-up falls today. */
    public function scopeFollowupToday(Builder $query): Builder
    {
        return $query->whereNotNull('next_followup_at')
            ->whereBetween('next_followup_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereNotIn('status', ['won', 'pass']);
    }

    /**
     * Leads someone started working and then let go cold. `new` is excluded on
     * purpose: an uncontacted fresh import is not a dropped ball, and counting
     * it would make the number the size of the last import.
     */
    public function scopeStale(Builder $query): Builder
    {
        return $query->whereIn('status', self::WORKING_STATUSES)
            ->where(fn (Builder $q) => $q
                ->whereNull('last_contacted_at')
                ->orWhere('last_contacted_at', '<', now()->subDays(self::STALE_DAYS)));
    }
```

- [ ] **Step 4: Implement `huntAttention()`**

In `app/Services/Reports/ReportsAggregator.php`, add after `huntDaily()`:

```php
    /**
     * What needs chasing right now. A CURRENT snapshot, deliberately not
     * date-filtered: an overdue follow-up is overdue no matter which period the
     * dashboard happens to be showing.
     *
     * Reads through Eloquent, which buys two things: AssignedLeadScope narrows
     * it to the acting agent, and the bucket definitions are the very Lead
     * scopes LeadController::index filters on — so a chip's number always
     * matches the list it opens.
     *
     * @return array{followups_overdue: int, followups_today: int, stale: int, unassigned: int}
     */
    public function huntAttention(int $shopId): array
    {
        $base = fn () => Lead::forShop($shopId);

        return [
            'followups_overdue' => $base()->followupOverdue()->count(),
            'followups_today'   => $base()->followupToday()->count(),
            'stale'             => $base()->stale()->count(),
            // No manager special-case: AssignedLeadScope rewrites an agent's
            // query to assigned_to_id = <them>, so this is 0 for them.
            'unassigned'        => $base()->whereNull('assigned_to_id')->count(),
        ];
    }
```

- [ ] **Step 5: Run the test**

```bash
bash ".../scratchpad/synctest.sh" --filter=HuntDashboardTest
```

Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Lead.php app/Services/Reports/ReportsAggregator.php tests/Feature/HuntDashboardTest.php
git commit -m "feat(reports): huntAttention() + shared Lead follow-up/stale scopes"
```

---

## Task 4: Leads list filters that match the counts

**Files:**
- Modify: `app/Http/Controllers/LeadController.php:538-542`
- Test: `tests/Feature/HuntDashboardTest.php`

**Interfaces:**
- Consumes: `Lead` scopes `followupOverdue()`, `followupToday()`, `stale()` (Task 3); `ReportsAggregator::huntAttention()` (Task 3).
- Produces: `GET /shop/leads` accepts `followups=overdue`, `followups=today` and `stale=1`. `followups=due` is unchanged.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/HuntDashboardTest.php`:

```php
    public function test_index_filters_match_the_attention_counts_exactly(): void
    {
        (new PermissionSeeder())->run();
        $shop = $this->attentionFixture();
        $this->startTrial($shop);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all']);
        $token = $this->tokenFor($shop, $manager);

        $counts = app(ReportsAggregator::class)->huntAttention($shop->id);

        $overdue = $this->authJson($token, 'GET', '/api/shop/leads?followups=overdue');
        $overdue->assertOk();
        $this->assertCount($counts['followups_overdue'], $overdue->json('data'));

        $today = $this->authJson($token, 'GET', '/api/shop/leads?followups=today');
        $today->assertOk();
        $this->assertCount($counts['followups_today'], $today->json('data'));

        $stale = $this->authJson($token, 'GET', '/api/shop/leads?stale=1');
        $stale->assertOk();
        $this->assertCount($counts['stale'], $stale->json('data'));

        $unassigned = $this->authJson($token, 'GET', '/api/shop/leads?assigned_to=unassigned');
        $unassigned->assertOk();
        $this->assertCount($counts['unassigned'], $unassigned->json('data'));
    }

    public function test_the_legacy_due_filter_still_omits_demo_stage(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $this->startTrial($shop);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all']);
        $token = $this->tokenFor($shop, $manager);

        Lead::factory()->create([
            'shop_id' => $shop->id, 'status' => 'demo',
            'next_followup_at' => now()->subDays(2),
        ]);

        // Pinned, not endorsed: `due` restricts to sent/followup/replied, so a
        // demo-stage lead with an overdue follow-up is invisible to it. The new
        // `overdue` filter does include it. Changing `due` is out of scope.
        $due = $this->authJson($token, 'GET', '/api/shop/leads?followups=due');
        $this->assertCount(0, $due->json('data'));

        $overdue = $this->authJson($token, 'GET', '/api/shop/leads?followups=overdue');
        $this->assertCount(1, $overdue->json('data'));
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
bash ".../scratchpad/synctest.sh" --filter=HuntDashboardTest
```

Expected: FAIL — the `overdue`/`today`/`stale` parameters are ignored, so those requests return all 5 fixture leads instead of 1 each.

- [ ] **Step 3: Implement the filters**

In `app/Http/Controllers/LeadController.php`, replace this block (currently lines 538-542):

```php
        if ($request->query('followups') === 'due') {
            $query->whereNotNull('next_followup_at')
                ->where('next_followup_at', '<=', now())
                ->whereIn('status', ['sent', 'followup', 'replied']);
        }
```

with:

```php
        // `due` is the original toggle on the Leads page and is left exactly as
        // it was. `overdue` / `today` / `stale` are the dashboard's chips, and
        // they share their definitions with ReportsAggregator::huntAttention
        // through the Lead scopes, so a chip's count always matches its list.
        $followups = $request->query('followups');
        if ($followups === 'due') {
            $query->whereNotNull('next_followup_at')
                ->where('next_followup_at', '<=', now())
                ->whereIn('status', ['sent', 'followup', 'replied']);
        } elseif ($followups === 'overdue') {
            $query->followupOverdue();
        } elseif ($followups === 'today') {
            $query->followupToday();
        }
        if ($request->boolean('stale')) {
            $query->stale();
        }
```

Also update the method's docblock line to:

```php
     * GET /shop/leads?status=&category=&search=&followups=due|overdue|today&stale=1
```

- [ ] **Step 4: Run the test**

```bash
bash ".../scratchpad/synctest.sh" --filter=HuntDashboardTest
```

Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LeadController.php tests/Feature/HuntDashboardTest.php
git commit -m "feat(leads): overdue/today/stale list filters matching the dashboard counts"
```

---

## Task 5: `GET /shop/reports/hunt`

**Files:**
- Modify: `app/Http/Controllers/ReportsController.php`, `routes/api.php`
- Test: `tests/Feature/HuntDashboardTest.php`

**Interfaces:**
- Consumes: `huntSummary()`, `huntByAgent()`, `huntDaily()` (Task 2), `huntAttention()` (Task 3), `HuntCreditService::balance(Shop $shop): int`.
- Produces: `GET /api/shop/reports/hunt?from&to` → JSON with keys `range`, `summary`, `daily`, `attention`, `agents`, `credits{balance,used,searches}`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/HuntDashboardTest.php`:

```php
    public function test_hunt_report_requires_authentication(): void
    {
        $this->getJson('/api/shop/reports/hunt?from=2026-07-01&to=2026-07-31')
            ->assertStatus(401);
    }

    public function test_hunt_report_requires_leads_view(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $this->startTrial($shop);
        $nobody = $this->agent($shop, ['profile.view']);

        $this->authJson($this->tokenFor($shop, $nobody), 'GET', '/api/shop/reports/hunt?from=2026-07-01&to=2026-07-31')
            ->assertStatus(403);
    }

    public function test_hunt_report_serves_a_manager_the_whole_shop(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $this->startTrial($shop);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all']);
        $worker = $this->agent($shop, ['leads.view']);

        Lead::factory()->create([
            'shop_id' => $shop->id, 'assigned_to_id' => $worker->id,
            'status' => 'won', 'deal_won_at' => '2026-07-10 12:00:00',
            'deal_amount' => 400, 'deal_type' => 'one_off',
            'created_at' => '2026-07-05 09:00:00',
        ]);

        $res = $this->authJson(
            $this->tokenFor($shop, $manager),
            'GET',
            '/api/shop/reports/hunt?from=2026-07-01&to=2026-07-31',
        );

        $res->assertOk()
            ->assertJsonStructure([
                'range' => ['from', 'to'],
                'summary' => ['new_leads', 'pipeline', 'won', 'won_value', 'mrr_won'],
                'daily' => [['date', 'leads', 'won', 'won_value']],
                'attention' => ['followups_overdue', 'followups_today', 'stale', 'unassigned'],
                'agents',
                'credits' => ['balance', 'used', 'searches'],
            ]);

        $this->assertSame(400.0, $res->json('summary.won_value'));
        $this->assertCount(31, $res->json('daily'));
        $this->assertSame($worker->id, $res->json('agents.0.id'));
    }

    public function test_hunt_report_gives_an_agent_only_their_own_numbers_and_no_leaderboard(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $this->startTrial($shop);
        $worker = $this->agent($shop, ['leads.view']);

        Lead::factory()->create(['shop_id' => $shop->id, 'assigned_to_id' => $worker->id, 'created_at' => '2026-07-05 09:00:00']);
        Lead::factory()->create(['shop_id' => $shop->id, 'created_at' => '2026-07-05 09:00:00']);

        $res = $this->authJson(
            $this->tokenFor($shop, $worker),
            'GET',
            '/api/shop/reports/hunt?from=2026-07-01&to=2026-07-31',
        );

        $res->assertOk();
        $this->assertSame(1, $res->json('summary.new_leads'));
        $this->assertSame([], $res->json('agents'));
    }

    public function test_hunt_report_ignores_a_request_supplied_shop_id(): void
    {
        (new PermissionSeeder())->run();
        $mine = Shop::factory()->create(['modules' => ['leads']]);
        $theirs = Shop::factory()->create(['modules' => ['leads']]);
        $this->startTrial($mine);
        $manager = $this->agent($mine, ['leads.view', 'leads.view_all']);

        Lead::factory()->count(3)->create(['shop_id' => $theirs->id, 'created_at' => '2026-07-05 09:00:00']);

        $res = $this->authJson(
            $this->tokenFor($mine, $manager),
            'GET',
            "/api/shop/reports/hunt?from=2026-07-01&to=2026-07-31&shop_id={$theirs->id}",
        );

        $res->assertOk();
        $this->assertSame(0, $res->json('summary.new_leads'));
    }

    public function test_hunt_report_is_closed_to_a_bookings_only_shop(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['bookings']]);
        $this->startTrial($shop);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all']);

        $this->authJson($this->tokenFor($shop, $manager), 'GET', '/api/shop/reports/hunt?from=2026-07-01&to=2026-07-31')
            ->assertStatus(403);
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
bash ".../scratchpad/synctest.sh" --filter=HuntDashboardTest
```

Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Add the controller method**

In `app/Http/Controllers/ReportsController.php`, add `use App\Services\Credits\HuntCreditService;` to the imports, then add this method after `insights()`:

```php
    /**
     * The Business Hunt dashboard payload — everything the page needs in one
     * round-trip. Agent scoping is handled inside the aggregator: huntSummary
     * filters explicitly, huntDaily/huntAttention inherit AssignedLeadScope,
     * and huntByAgent returns [] for anyone who can't see all leads, which is
     * what hides the leaderboard from agents.
     */
    public function hunt(Request $request, HuntCreditService $credits)
    {
        [$shopId, $from, $to] = $this->validated($request);
        $summary = $this->aggregator->huntSummary($shopId, $from, $to);

        return response()->json([
            'range'     => $summary['range'],
            'summary'   => $summary,
            'daily'     => $this->aggregator->huntDaily($shopId, $from, $to),
            'attention' => $this->aggregator->huntAttention($shopId),
            'agents'    => $this->aggregator->huntByAgent($shopId, $from, $to),
            'credits'   => [
                'balance'  => $credits->balance(Shop::findOrFail($shopId)),
                'used'     => $summary['credits_used'],
                'searches' => $summary['searches'],
            ],
        ]);
    }
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, immediately after the AI-summary group (which ends around line 133), add:

```php
// Business Hunt dashboard — its own group because the reports group above
// requires the bookings module, which would lock out a Hunt-only shop. Needs
// rbac.context: without it current_shop_user() is null, every agent is treated
// as owner-equivalent, and the whole shop's pipeline leaks.
Route::middleware(['auth:sanctum', 'rbac.context', 'module:leads', 'can.perm:leads.view'])
    ->group(function () {
        Route::get('/shop/reports/hunt', [\App\Http\Controllers\ReportsController::class, 'hunt']);
    });
```

- [ ] **Step 5: Run the test**

```bash
bash ".../scratchpad/synctest.sh" --filter=HuntDashboardTest
```

Expected: PASS (15 tests).

- [ ] **Step 6: Run the whole backend suite**

```bash
bash ".../scratchpad/synctest.sh"
```

Expected: PASS — 596 prior tests plus the 16 added here.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ReportsController.php routes/api.php tests/Feature/HuntDashboardTest.php
git commit -m "feat(reports): GET /shop/reports/hunt dashboard endpoint"
```

---

## Task 6: Characterization test for `/insights`

**Files:**
- Create: `admin/src/pages/Insights.test.tsx`

**Interfaces:**
- Produces: a test that pins the current rendered output of `Insights.tsx`, so Task 7's extraction is provably behaviour-preserving.

`Insights.tsx` has no tests today. Extracting components out of it blind is how a working page quietly breaks. Write the net first.

- [ ] **Step 1: Write the test**

Create `admin/src/pages/Insights.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as insightsLib from '@/lib/insights';
import type { Insights as InsightsData } from '@/lib/insights';
import Insights from './Insights';

/**
 * A characterization test: it documents what the page renders TODAY so the
 * chart components can be extracted out of it without silently changing the
 * output. If a change here is intentional, update the expectation — but never
 * without looking at the page.
 */
function payload(over: Partial<InsightsData> = {}): InsightsData {
  return {
    range: { from: '2026-07-01', to: '2026-07-03' },
    bookings: { scheduled: 10, booked: 2, completed: 6, cancelled: 1, no_show: 1 },
    rates: { completion: 60, cancellation: 10, no_show: 10 },
    customers: { total: 8, returning: 3, new: 5, repeat_rate: 37.5 },
    reviews: { count: 4, average: 4.5 },
    daily: [
      { date: '2026-07-01', completed: 2, cancelled: 0, no_show: 0, booked: 1, total: 3 },
      { date: '2026-07-02', completed: 3, cancelled: 1, no_show: 0, booked: 0, total: 4 },
      { date: '2026-07-03', completed: 1, cancelled: 0, no_show: 1, booked: 1, total: 3 },
    ],
    ...over,
  };
}

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme', modules: ['bookings'] });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Insights /></ShopProvider></MemoryRouter>);
}

describe('Insights', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('renders the KPI row, charts and review summary', async () => {
    vi.spyOn(insightsLib, 'getInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('Total bookings')).toBeInTheDocument();
    expect(screen.getByText('Completion')).toBeInTheDocument();
    expect(screen.getByText('No-show rate')).toBeInTheDocument();
    expect(screen.getByText('Avg rating')).toBeInTheDocument();

    // Chart cards.
    expect(screen.getByText('Bookings over time')).toBeInTheDocument();
    expect(screen.getByText('Outcomes')).toBeInTheDocument();
    expect(screen.getByText('Rates')).toBeInTheDocument();
    expect(screen.getByText('Customers')).toBeInTheDocument();
    expect(screen.getByText('Reviews')).toBeInTheDocument();

    // Donut legend carries the outcome values.
    expect(screen.getByText('Completed')).toBeInTheDocument();
    expect(screen.getByText('4 reviews in this range')).toBeInTheDocument();
    expect(screen.getByText('4.5')).toBeInTheDocument();
  });

  it('offers the quick range presets', async () => {
    vi.spyOn(insightsLib, 'getInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByRole('button', { name: '30 days' })).toHaveAttribute('aria-pressed', 'true');
    ['7 days', '90 days', 'This month', 'Last month', 'This year'].forEach((label) => {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
    });
  });

  it('shows empty states rather than broken charts when there is no data', async () => {
    vi.spyOn(insightsLib, 'getInsights').mockResolvedValue(payload({
      bookings: { scheduled: 0, booked: 0, completed: 0, cancelled: 0, no_show: 0 },
      customers: { total: 0, returning: 0, new: 0, repeat_rate: 0 },
      reviews: { count: 0, average: null },
      daily: [{ date: '2026-07-01', completed: 0, cancelled: 0, no_show: 0, booked: 0, total: 0 }],
    }));

    setup();

    expect(await screen.findByText('No bookings in this range yet.')).toBeInTheDocument();
    expect(screen.getByText('No reviews yet')).toBeInTheDocument();
  });

  it('surfaces a load failure', async () => {
    vi.spyOn(insightsLib, 'getInsights').mockRejectedValue(new Error('nope'));

    setup();

    expect(await screen.findByText('Could not load insights.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run it against the CURRENT code**

```bash
cd admin && npx vitest run src/pages/Insights.test.tsx
```

Expected: **PASS** (4 tests). If any assertion fails, the expectation is wrong — read the page and fix the test, do not touch `Insights.tsx`.

- [ ] **Step 3: Commit**

```bash
git add admin/src/pages/Insights.test.tsx
git commit -m "test(insights): characterization test before extracting chart components"
```

---

## Task 7: Extract the shared chart components

**Files:**
- Create: `admin/src/lib/dateRange.ts`, `admin/src/components/charts/{ChartCard,Kpi,EmptyState,Donut,RateBars,TrendChart,RangeFilter}.tsx`
- Create: `admin/src/components/charts/charts.test.tsx`
- Modify: `admin/src/pages/Insights.tsx`

**Interfaces:**
- Produces:
  - `dateRange.ts`: `PresetKey`, `PRESETS`, `presetRange(key, today): {from,to}`, `iso(Date)`, `parseISO(string)`, `addDays(Date, n)`, `daysBetween(from,to)`, `fmtLong(s)`, `fmtShort(s)`, `fmtNum(n)`, `pctChange(cur, prev): number|null`, `previousRange(from,to): {from,to}`
  - `ChartCard({icon, title, sub, span2?, children})`
  - `Kpi({label, value, unit?, delta})`, `Delta({change, display, goodDir})`
  - `EmptyState({text})`
  - `Donut({segments: {key,label,value,color}[], cap})`
  - `RateBars({rows: {label,value,color}[]})`
  - `TrendChart({series: {key,label,color,points:{date,value}[]}[], unitLabel})`
  - `RangeFilter({preset, from, to, onPreset, onFrom, onTo})`

The components keep their existing `ins-*` class names and `insights.css` stays their stylesheet, so nothing visual moves.

- [ ] **Step 1: Create `admin/src/lib/dateRange.ts`**

```ts
/**
 * Date-range helpers shared by the report pages (/insights, /hunt-insights).
 * Lifted verbatim out of Insights.tsx when the Hunt dashboard needed them —
 * behaviour is unchanged, see Insights.test.tsx.
 */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const pad = (n: number) => String(n).padStart(2, '0');

export const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const parseISO = (s: string) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
export const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
export const daysBetween = (from: string, to: string) =>
  Math.round((parseISO(to).getTime() - parseISO(from).getTime()) / 86_400_000) + 1;
export const fmtLong = (s: string) => { const d = parseISO(s); return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`; };
export const fmtShort = (s: string) => { const d = parseISO(s); return `${d.getDate()} ${MONTHS[d.getMonth()]}`; };
export const fmtNum = (n: number) => n.toLocaleString();

export type PresetKey = '7d' | '30d' | '90d' | 'mtd' | 'lastmonth' | 'ytd' | 'custom';

export const PRESETS: { key: Exclude<PresetKey, 'custom'>; label: string }[] = [
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '90d', label: '90 days' },
  { key: 'mtd', label: 'This month' },
  { key: 'lastmonth', label: 'Last month' },
  { key: 'ytd', label: 'This year' },
];

export function presetRange(key: Exclude<PresetKey, 'custom'>, today: Date): { from: string; to: string } {
  const y = today.getFullYear();
  const m = today.getMonth();
  switch (key) {
    case '7d': return { from: iso(addDays(today, -6)), to: iso(today) };
    case '30d': return { from: iso(addDays(today, -29)), to: iso(today) };
    case '90d': return { from: iso(addDays(today, -89)), to: iso(today) };
    case 'mtd': return { from: iso(new Date(y, m, 1)), to: iso(new Date(y, m + 1, 0)) };
    case 'lastmonth': return { from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) };
    case 'ytd': return { from: iso(new Date(y, 0, 1)), to: iso(today) };
  }
}

/** The equal-length window immediately before [from, to], for deltas. */
export function previousRange(from: string, to: string): { from: string; to: string } {
  const len = daysBetween(from, to);
  const pTo = iso(addDays(parseISO(from), -1));
  return { from: iso(addDays(parseISO(pTo), -(len - 1))), to: pTo };
}

/** Percent change, or null when there's no prior figure to compare against. */
export const pctChange = (cur: number, prev: number): number | null => {
  if (prev === 0) return cur === 0 ? 0 : null;
  return ((cur - prev) / prev) * 100;
};
```

- [ ] **Step 2: Create the simple components**

`admin/src/components/charts/EmptyState.tsx`:

```tsx
import { Icons } from '@/components/Icons';

/** Shown in place of a chart when there is nothing to plot. */
export function EmptyState({ text }: { text: string }) {
  return (
    <div className="ins-empty">
      <span className="ins-empty-ic"><Icons.Chart size={26} /></span>
      <span className="ins-empty-txt">{text}</span>
    </div>
  );
}
```

`admin/src/components/charts/ChartCard.tsx`:

```tsx
import type { ReactNode } from 'react';
import { Icons } from '@/components/Icons';

/** Card shell for a chart: icon, title, subtitle, body. */
export function ChartCard({ icon, title, sub, span2, children }: {
  icon: keyof typeof Icons; title: string; sub: string; span2?: boolean; children: ReactNode;
}) {
  const Icon = Icons[icon];
  return (
    <div className={`ins-card${span2 ? ' span2' : ''}`}>
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icon size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">{title}</span>
          <span className="ins-card-sub">{sub}</span>
        </span>
      </div>
      {children}
    </div>
  );
}
```

`admin/src/components/charts/Kpi.tsx`:

```tsx
import type { ReactNode } from 'react';

/**
 * The vs-previous-period indicator. `goodDir` says which direction is good, so
 * a falling no-show rate reads as green and a falling revenue reads as red.
 */
export function Delta({ change, display, goodDir }: {
  change: number | null; display: string; goodDir: 'up' | 'down';
}) {
  if (change === null) return <span className="ins-kpi-delta flat"><span className="vs">no prior data</span></span>;
  const arrow = change > 0 ? '▲' : change < 0 ? '▼' : '—';
  const cls = change === 0 ? 'flat' : (change > 0) === (goodDir === 'up') ? 'up' : 'down';
  return <span className={`ins-kpi-delta ${cls}`}>{arrow} {display} <span className="vs">vs prev</span></span>;
}

export function Kpi({ label, value, unit, delta }: {
  label: string; value: string; unit?: string; delta: ReactNode;
}) {
  return (
    <div className="ins-kpi">
      <span className="ins-kpi-label">{label}</span>
      <span className="ins-kpi-value">{value}{unit && <span className="u">{unit}</span>}</span>
      {delta}
    </div>
  );
}
```

`admin/src/components/charts/RateBars.tsx`:

```tsx
/** Labelled percentage bars, clamped to 0-100. */
export function RateBars({ rows }: { rows: { label: string; value: number; color: string }[] }) {
  return (
    <div className="ins-rates">
      {rows.map((r) => (
        <div key={r.label}>
          <div className="ins-rate-head">
            <span className="ins-rate-lab">{r.label}</span>
            <span className="ins-rate-val">{r.value}%</span>
          </div>
          <div className="ins-rate-track">
            <div className="ins-rate-fill" style={{ width: `${Math.max(0, Math.min(100, r.value))}%`, background: r.color }} />
          </div>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 3: Create `admin/src/components/charts/Donut.tsx`**

Move the `Seg` type and `Donut` function out of `Insights.tsx` verbatim, adding the import:

```tsx
import { EmptyState } from './EmptyState';
import { fmtNum } from '@/lib/dateRange';

export type Seg = { key: string; label: string; value: number; color: string };

/** Proportional ring with a value legend. Empty state when everything is zero. */
export function Donut({ segments, cap, emptyText = 'Nothing to break down yet.' }: {
  segments: Seg[]; cap: string; emptyText?: string;
}) {
  const total = segments.reduce((s, x) => s + x.value, 0);
  const r = 52, cx = 66, cy = 66, sw = 16, Circ = 2 * Math.PI * r;
  if (total === 0) return <EmptyState text={emptyText} />;
  let offset = 0;
  return (
    <div className="ins-donut-row">
      <div className="ins-donut" aria-hidden="true">
        <svg viewBox="0 0 132 132">
          <circle cx={cx} cy={cy} r={r} fill="none" stroke="var(--neutral-soft)" strokeWidth={sw} />
          {segments.filter((s) => s.value > 0).map((s) => {
            const dash = (s.value / total) * Circ;
            const el = (
              <circle key={s.key} className="ins-donut-seg" cx={cx} cy={cy} r={r} fill="none"
                stroke={s.color} strokeWidth={sw} strokeLinecap="butt"
                strokeDasharray={`${Math.max(dash - 2, 0.001)} ${Circ}`} strokeDashoffset={-offset}>
                <title>{`${s.label}: ${fmtNum(s.value)} (${Math.round((s.value / total) * 100)}%)`}</title>
              </circle>
            );
            offset += dash;
            return el;
          })}
        </svg>
        <div className="ins-donut-center">
          <span className="ins-donut-total">{fmtNum(total)}</span>
          <span className="ins-donut-cap">{cap}</span>
        </div>
      </div>
      <div className="ins-legend">
        {segments.map((s) => (
          <div key={s.key} className="ins-legend-item">
            <span className="ins-legend-dot" style={{ background: s.color }} />
            <span className="ins-legend-lab">{s.label}</span>
            <span className="ins-legend-val">{fmtNum(s.value)}</span>
            <span className="ins-legend-pct">{total ? Math.round((s.value / total) * 100) : 0}%</span>
          </div>
        ))}
      </div>
    </div>
  );
}
```

The `emptyText` prop is new — `/insights` passes `"No bookings to break down yet."` to keep its current copy exactly.

- [ ] **Step 4: Create `admin/src/components/charts/TrendChart.tsx`**

Generalised to N series. One series behaves exactly as the old chart did (gradient fill under the line); additional series draw as lines only.

```tsx
import { useMemo, useState } from 'react';
import { EmptyState } from './EmptyState';
import { fmtNum, fmtShort, fmtLong } from '@/lib/dateRange';

export type TrendPoint = { date: string; value: number };
export type TrendSeries = { key: string; label: string; color: string; points: TrendPoint[] };

/**
 * Bucket a series down to at most `maxPoints` by summing consecutive days, so a
 * year-long range stays readable. Returns `bucketed` so the tooltip can say
 * "week of" instead of a single date.
 */
function downsample(points: TrendPoint[], maxPoints = 45): { points: TrendPoint[]; bucketed: boolean } {
  if (points.length <= maxPoints) return { points, bucketed: false };
  const size = Math.ceil(points.length / maxPoints);
  const out: TrendPoint[] = [];
  for (let i = 0; i < points.length; i += size) {
    const chunk = points.slice(i, i + size);
    out.push({ date: chunk[0].date, value: chunk.reduce((s, c) => s + c.value, 0) });
  }
  return { points: out, bucketed: true };
}

export function TrendChart({ series, emptyText = 'Nothing to plot in this range yet.' }: {
  series: TrendSeries[]; emptyText?: string;
}) {
  const [hover, setHover] = useState<number | null>(null);

  const reduced = useMemo(
    () => series.map((s) => ({ ...s, ...downsample(s.points) })),
    [series],
  );

  const n = reduced[0]?.points.length ?? 0;
  const bucketed = reduced[0]?.bucketed ?? false;
  const grand = reduced.reduce((s, ser) => s + ser.points.reduce((a, p) => a + p.value, 0), 0);
  if (n === 0 || grand === 0) return <EmptyState text={emptyText} />;

  const W = 600, H = 200, top = 18, bottom = 26;
  const plotH = H - top - bottom;
  const maxY = Math.max(1, ...reduced.flatMap((s) => s.points.map((p) => p.value)));
  const niceMax = Math.ceil(maxY / 4) * 4 || 4;
  const x = (i: number) => (n === 1 ? W / 2 : (i / (n - 1)) * W);
  const y = (v: number) => top + plotH * (1 - v / niceMax);

  const path = (pts: TrendPoint[]) =>
    pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' ');

  const gridVals = [0, niceMax / 2, niceMax];

  const onMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const r = e.currentTarget.getBoundingClientRect();
    const frac = (e.clientX - r.left) / r.width;
    setHover(Math.max(0, Math.min(n - 1, Math.round(frac * (n - 1)))));
  };

  const label = series.map((s) => s.label).join(' and ');

  return (
    <div className="ins-chartbox" onPointerMove={onMove} onPointerLeave={() => setHover(null)}>
      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" role="img" aria-label={`${label} over time`}>
        <defs>
          <linearGradient id="insTrend" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={reduced[0].color} stopOpacity="0.34" />
            <stop offset="100%" stopColor={reduced[0].color} stopOpacity="0.02" />
          </linearGradient>
        </defs>
        {gridVals.map((v, i) => (
          <g key={i}>
            <line className="ins-grid-line" x1={0} x2={W} y1={y(v)} y2={y(v)} />
            <text className="ins-axis-lab" x={2} y={y(v) - 3}>{Math.round(v)}</text>
          </g>
        ))}
        {/* The first series gets the filled area, the rest are lines. */}
        <path d={`${path(reduced[0].points)} L${x(n - 1).toFixed(1)},${top + plotH} L${x(0).toFixed(1)},${top + plotH} Z`} fill="url(#insTrend)" />
        {reduced.map((s) => (
          <path key={s.key} d={path(s.points)} fill="none" stroke={s.color} strokeWidth={2}
            strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        ))}
        {hover !== null && (
          <g>
            <line className="ins-grid-line" x1={x(hover)} x2={x(hover)} y1={top} y2={top + plotH} stroke="var(--border-3)" />
            {reduced.map((s) => (
              <circle key={s.key} cx={x(hover)} cy={y(s.points[hover].value)} r={4}
                fill={s.color} stroke="var(--bg-1)" strokeWidth={2} vectorEffect="non-scaling-stroke" />
            ))}
          </g>
        )}
        {[0, Math.floor((n - 1) / 2), n - 1].filter((v, i, a) => a.indexOf(v) === i && v >= 0).map((i) => (
          <text key={i} className="ins-axis-lab" x={Math.max(12, Math.min(W - 12, x(i)))} y={H - 8}
            textAnchor={i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}>{fmtShort(reduced[0].points[i].date)}</text>
        ))}
      </svg>
      {hover !== null && (
        <div className="ins-tooltip" style={{ left: `${(x(hover) / W) * 100}%`, top: `${(y(reduced[0].points[hover].value) / H) * 100}%` }}>
          <div className="ins-tt-date">{bucketed ? `Week of ${fmtShort(reduced[0].points[hover].date)}` : fmtLong(reduced[0].points[hover].date)}</div>
          {reduced.map((s) => (
            <div key={s.key} className="ins-tt-val">
              <span className="d">{fmtNum(s.points[hover].value)}</span> {s.label}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 5: Create `admin/src/components/charts/RangeFilter.tsx`**

```tsx
import { PRESETS, fmtLong, daysBetween, type PresetKey } from '@/lib/dateRange';

/** Quick-range segmented control plus a custom from/to pair. */
export function RangeFilter({ preset, from, to, onPreset, onFrom, onTo }: {
  preset: PresetKey;
  from: string;
  to: string;
  onPreset: (key: Exclude<PresetKey, 'custom'>) => void;
  onFrom: (v: string) => void;
  onTo: (v: string) => void;
}) {
  const len = daysBetween(from, to);
  return (
    <div className="ins-filter">
      <div className="ins-seg" role="group" aria-label="Quick ranges">
        {PRESETS.map((p) => (
          <button key={p.key} className={`ins-seg-btn${preset === p.key ? ' on' : ''}`}
            aria-pressed={preset === p.key} onClick={() => onPreset(p.key)}>{p.label}</button>
        ))}
      </div>
      <div className="ins-range-row">
        <span className="ins-range-lab">Custom</span>
        <input className="ins-date-input" type="date" value={from} max={to}
          onChange={(e) => onFrom(e.target.value)} aria-label="From date" />
        <span className="ins-date-dash">→</span>
        <input className="ins-date-input" type="date" value={to} min={from}
          onChange={(e) => onTo(e.target.value)} aria-label="To date" />
        <span className="ins-active"><b>{fmtLong(from)}</b> – <b>{fmtLong(to)}</b> · {len} day{len === 1 ? '' : 's'}</span>
      </div>
    </div>
  );
}
```

- [ ] **Step 6: Rewire `Insights.tsx`**

In `admin/src/pages/Insights.tsx`:

1. Delete the local `MONTHS`/`pad`/`iso`/`parseISO`/`addDays`/`daysBetween`/`fmtLong`/`fmtShort`/`fmtNum` helpers, the `PresetKey`/`PRESETS`/`presetRange` block, the `pctChange` function, and the `ChartCard`, `Delta`, `Kpi`, `EmptyState`, `downsample`, `TrendChart`, `Donut`, `RateBars` components. Keep `C` (colour roles), `Reviews`, `Skeleton` and the page component.
2. Add the imports:

```tsx
import { ChartCard } from '@/components/charts/ChartCard';
import { Donut } from '@/components/charts/Donut';
import { EmptyState } from '@/components/charts/EmptyState';
import { Kpi, Delta } from '@/components/charts/Kpi';
import { RangeFilter } from '@/components/charts/RangeFilter';
import { RateBars } from '@/components/charts/RateBars';
import { TrendChart } from '@/components/charts/TrendChart';
import {
  daysBetween, fmtNum, iso, parseISO, addDays, pctChange, presetRange, previousRange, type PresetKey,
} from '@/lib/dateRange';
```

(`EmptyState`, `iso`, `parseISO`, `addDays` remain used by `Reviews`/`fetchData`; drop any that your editor reports as unused rather than leaving a lint error.)

3. Replace the inline filter markup (the `<div className="ins-filter">…</div>` block) with:

```tsx
        <RangeFilter
          preset={preset} from={from} to={to}
          onPreset={choosePreset}
          onFrom={(v) => { setFrom(v); setPreset('custom'); }}
          onTo={(v) => { setTo(v); setPreset('custom'); }}
        />
```

Note `RangeFilter` receives the **normalised** `nf`/`nt`? No — pass the raw `from`/`to`, matching today's inputs. The normalised `nf`/`nt` stay in use for fetching only.

4. Replace the previous-period computation inside `fetchData` with:

```tsx
      const prevR = previousRange(nf, nt);
```

and use `getInsights(shop.id, prevR.from, prevR.to)`.

5. Replace the trend card body with the new series shape:

```tsx
              <TrendChart
                series={[{
                  key: 'bookings',
                  label: 'bookings',
                  color: 'var(--mint-300)',
                  points: data.daily.map((d) => ({ date: d.date, value: d.total })),
                }]}
                emptyText="No bookings in this range yet."
              />
```

6. Pass the original empty copy to the two donuts: `emptyText="No bookings to break down yet."` on both.

- [ ] **Step 7: Run the characterization test**

```bash
cd admin && npx vitest run src/pages/Insights.test.tsx
```

Expected: PASS, all 4 — unchanged. If anything fails, the extraction changed behaviour: fix the component, not the test.

- [ ] **Step 8: Write component unit tests**

Create `admin/src/components/charts/charts.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Donut } from './Donut';
import { RateBars } from './RateBars';
import { TrendChart } from './TrendChart';
import { Kpi, Delta } from './Kpi';
import { EmptyState } from './EmptyState';
import { previousRange, pctChange, daysBetween, presetRange } from '@/lib/dateRange';

describe('Donut', () => {
  it('renders a legend with values and percentages', () => {
    render(<Donut cap="Total" segments={[
      { key: 'a', label: 'Alpha', value: 3, color: 'red' },
      { key: 'b', label: 'Beta', value: 1, color: 'blue' },
    ]} />);
    expect(screen.getByText('Alpha')).toBeInTheDocument();
    expect(screen.getByText('75%')).toBeInTheDocument();
    expect(screen.getByText('25%')).toBeInTheDocument();
  });

  it('falls back to an empty state when every segment is zero', () => {
    render(<Donut cap="Total" emptyText="nothing here" segments={[
      { key: 'a', label: 'Alpha', value: 0, color: 'red' },
    ]} />);
    expect(screen.getByText('nothing here')).toBeInTheDocument();
  });
});

describe('RateBars', () => {
  it('clamps a bar to 100%', () => {
    const { container } = render(<RateBars rows={[{ label: 'Over', value: 240, color: 'red' }]} />);
    expect(screen.getByText('240%')).toBeInTheDocument();
    expect((container.querySelector('.ins-rate-fill') as HTMLElement).style.width).toBe('100%');
  });
});

describe('TrendChart', () => {
  const pts = (vals: number[]) => vals.map((v, i) => ({ date: `2026-07-0${i + 1}`, value: v }));

  it('plots one path per series', () => {
    const { container } = render(<TrendChart series={[
      { key: 'in', label: 'leads', color: 'green', points: pts([1, 2, 3]) },
      { key: 'won', label: 'wins', color: 'gold', points: pts([0, 1, 0]) },
    ]} />);
    // One filled area + two lines.
    expect(container.querySelectorAll('path')).toHaveLength(3);
    expect(screen.getByRole('img', { name: 'leads and wins over time' })).toBeInTheDocument();
  });

  it('shows the empty state when every value is zero', () => {
    render(<TrendChart series={[{ key: 'in', label: 'leads', color: 'green', points: pts([0, 0]) }]} emptyText="no data" />);
    expect(screen.getByText('no data')).toBeInTheDocument();
  });
});

describe('Kpi', () => {
  it('marks a rise as good when up is good, and bad when down is good', () => {
    const { container: up } = render(
      <Kpi label="Won" value="5" delta={<Delta change={20} display="20%" goodDir="up" />} />);
    expect(up.querySelector('.ins-kpi-delta')?.className).toContain('up');

    const { container: down } = render(
      <Kpi label="No-show" value="5" delta={<Delta change={20} display="20%" goodDir="down" />} />);
    expect(down.querySelector('.ins-kpi-delta')?.className).toContain('down');
  });

  it('says so when there is no prior period', () => {
    render(<Kpi label="Won" value="5" delta={<Delta change={null} display="" goodDir="up" />} />);
    expect(screen.getByText('no prior data')).toBeInTheDocument();
  });
});

describe('EmptyState', () => {
  it('shows its text', () => {
    render(<EmptyState text="nothing yet" />);
    expect(screen.getByText('nothing yet')).toBeInTheDocument();
  });
});

describe('dateRange', () => {
  it('computes the immediately preceding window of equal length', () => {
    expect(previousRange('2026-07-08', '2026-07-14')).toEqual({ from: '2026-07-01', to: '2026-07-07' });
  });

  it('counts days inclusively', () => {
    expect(daysBetween('2026-07-01', '2026-07-01')).toBe(1);
    expect(daysBetween('2026-07-01', '2026-07-07')).toBe(7);
  });

  it('returns null percent change when there is no baseline, and 0 when both are zero', () => {
    expect(pctChange(5, 0)).toBeNull();
    expect(pctChange(0, 0)).toBe(0);
    expect(pctChange(150, 100)).toBe(50);
  });

  it('builds a 7-day preset ending today', () => {
    expect(presetRange('7d', new Date(2026, 6, 24))).toEqual({ from: '2026-07-18', to: '2026-07-24' });
  });
});
```

- [ ] **Step 9: Run the full frontend suite**

```bash
cd admin && npx vitest run
```

Expected: PASS — 226 prior tests plus the new ones. No regressions.

- [ ] **Step 10: Commit**

```bash
git add admin/src/lib/dateRange.ts admin/src/components/charts admin/src/pages/Insights.tsx
git commit -m "refactor(admin): extract shared chart components + dateRange helpers"
```

---

## Task 8: Hunt dashboard API client

**Files:**
- Create: `admin/src/lib/huntInsights.ts`
- Modify: `admin/src/lib/leads.ts` (filter params)

**Interfaces:**
- Produces: `getHuntInsights(from: string, to: string): Promise<HuntInsights>` and the types `HuntInsights`, `HuntDaily`, `HuntAttention`, `HuntAgentRow`. Also extends `LeadFilters` with `stale?: boolean` and widens `followups` to `'due' | 'overdue' | 'today'`.

- [ ] **Step 1: Create `admin/src/lib/huntInsights.ts`**

```ts
import api from './api';
import type { LeadStatus } from '@/types';

export type HuntDaily = { date: string; leads: number; won: number; won_value: number };

export type HuntAttention = {
  followups_overdue: number;
  followups_today: number;
  stale: number;
  unassigned: number;
};

export type HuntAgentRow = { id: number; name: string; leads: number; won: number; won_value: number };

export type HuntSummary = {
  range: { from: string; to: string };
  new_leads: number;
  pipeline: Record<LeadStatus, number>;
  total_leads: number;
  moved: Record<LeadStatus, number>;
  won: number;
  won_value: number;
  won_value_recurring: number;
  won_value_one_off: number;
  mrr_won: number;
  credits_used: number;
  searches: number;
};

export type HuntInsights = {
  range: { from: string; to: string };
  summary: HuntSummary;
  daily: HuntDaily[];
  attention: HuntAttention;
  /** Empty for an agent — the backend hides the leaderboard from them. */
  agents: HuntAgentRow[];
  credits: { balance: number; used: number; searches: number };
};

const EMPTY_FUNNEL: Record<LeadStatus, number> =
  { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 };

/**
 * The whole Business Hunt dashboard in one request. The shop comes from the
 * auth token, so there is no shop_id parameter to pass.
 */
export async function getHuntInsights(from: string, to: string): Promise<HuntInsights> {
  const { data } = await api.get('/shop/reports/hunt', { params: { from, to } });
  return {
    range: data?.range ?? { from, to },
    summary: {
      ...data?.summary,
      pipeline: { ...EMPTY_FUNNEL, ...(data?.summary?.pipeline ?? {}) },
      moved: { ...EMPTY_FUNNEL, ...(data?.summary?.moved ?? {}) },
    },
    daily: Array.isArray(data?.daily) ? data.daily : [],
    attention: data?.attention ?? { followups_overdue: 0, followups_today: 0, stale: 0, unassigned: 0 },
    agents: Array.isArray(data?.agents) ? data.agents : [],
    credits: data?.credits ?? { balance: 0, used: 0, searches: 0 },
  };
}
```

- [ ] **Step 2: Widen the lead filters**

In `admin/src/lib/leads.ts`, find the `LeadFilters` type and change its `followups` field and add `stale`:

```ts
  /** `due` is the Leads page toggle; `overdue`/`today` are the dashboard chips. */
  followups?: 'due' | 'overdue' | 'today';
  /** Worked leads that have gone cold (no contact in 14 days). */
  stale?: boolean;
```

If `LeadFilters` lives in `admin/src/types.ts` rather than `leads.ts`, change it there instead — search for `followups?:` to locate it.

- [ ] **Step 3: Typecheck**

```bash
cd admin && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add admin/src/lib/huntInsights.ts admin/src/lib/leads.ts admin/src/types.ts
git commit -m "feat(admin): Hunt dashboard API client + overdue/today/stale lead filters"
```

---

## Task 9: The `/hunt-insights` page

**Files:**
- Create: `admin/src/pages/HuntInsights.tsx`, `admin/src/styles/hunt-insights.css`
- Create: `admin/src/pages/HuntInsights.test.tsx`

**Interfaces:**
- Consumes: `getHuntInsights` (Task 8), all chart components + `dateRange` helpers (Task 7).
- Produces: default-exported `HuntInsights` page component.

- [ ] **Step 1: Write the failing test**

Create `admin/src/pages/HuntInsights.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as lib from '@/lib/huntInsights';
import type { HuntInsights as Data } from '@/lib/huntInsights';
import HuntInsights from './HuntInsights';

function payload(over: Partial<Data> = {}): Data {
  return {
    range: { from: '2026-07-01', to: '2026-07-03' },
    summary: {
      range: { from: '2026-07-01', to: '2026-07-03' },
      new_leads: 12,
      pipeline: { new: 40, sent: 12, followup: 8, replied: 5, demo: 3, won: 4, pass: 6 },
      total_leads: 78,
      moved: { new: 0, sent: 5, followup: 3, replied: 2, demo: 1, won: 2, pass: 1 },
      won: 4,
      won_value: 9000,
      won_value_recurring: 6000,
      won_value_one_off: 3000,
      mrr_won: 1000,
      credits_used: 18,
      searches: 18,
    },
    daily: [
      { date: '2026-07-01', leads: 5, won: 1, won_value: 2000 },
      { date: '2026-07-02', leads: 4, won: 0, won_value: 0 },
      { date: '2026-07-03', leads: 3, won: 3, won_value: 7000 },
    ],
    attention: { followups_overdue: 6, followups_today: 3, stale: 11, unassigned: 24 },
    agents: [
      { id: 3, name: 'Sara', leads: 41, won: 3, won_value: 7000 },
      { id: 4, name: 'Omar', leads: 12, won: 1, won_value: 2000 },
    ],
    credits: { balance: 120, used: 18, searches: 18 },
    ...over,
  };
}

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme', modules: ['leads'] });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><HuntInsights /></ShopProvider></MemoryRouter>);
}

describe('HuntInsights', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('shows the four headline KPIs', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('New leads')).toBeInTheDocument();
    expect(screen.getByText('Deals won')).toBeInTheDocument();
    expect(screen.getByText('Won value')).toBeInTheDocument();
    expect(screen.getByText('MRR won')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument();
  });

  it('links each attention chip to the matching filtered list', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByRole('link', { name: /6 Overdue/ }))
      .toHaveAttribute('href', '/leads?followups=overdue');
    expect(screen.getByRole('link', { name: /3 Due today/ }))
      .toHaveAttribute('href', '/leads?followups=today');
    expect(screen.getByRole('link', { name: /11 Going cold/ }))
      .toHaveAttribute('href', '/leads?stale=1');
    expect(screen.getByRole('link', { name: /24 Unassigned/ }))
      .toHaveAttribute('href', '/leads?assigned_to=unassigned');
  });

  it('hides the unassigned chip when there are none', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload({
      attention: { followups_overdue: 1, followups_today: 0, stale: 0, unassigned: 0 },
    }));

    setup();

    expect(await screen.findByRole('link', { name: /1 Overdue/ })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Unassigned/ })).toBeNull();
  });

  it('shows the agent leaderboard to a manager', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('Agent leaderboard')).toBeInTheDocument();
    expect(screen.getByText('Sara')).toBeInTheDocument();
    expect(screen.getByText('Omar')).toBeInTheDocument();
  });

  it('hides the leaderboard when the backend returns no agents', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload({ agents: [] }));

    setup();

    expect(await screen.findByText('New leads')).toBeInTheDocument();
    expect(screen.queryByText('Agent leaderboard')).toBeNull();
  });

  it('shows the funnel and the decided win rate', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('Pipeline')).toBeInTheDocument();
    // 4 won of 10 decided (4 won + 6 pass) = 40%.
    expect(screen.getByText('40% of decided leads won')).toBeInTheDocument();
  });

  it('shows the credit balance', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('120')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /buy credits/i })).toHaveAttribute('href', '/leads/credits');
  });

  it('surfaces a load failure', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockRejectedValue(new Error('nope'));

    setup();

    expect(await screen.findByText('Could not load your Hunt stats.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd admin && npx vitest run src/pages/HuntInsights.test.tsx
```

Expected: FAIL — `Failed to resolve import "./HuntInsights"`.

- [ ] **Step 3: Create the stylesheet**

Create `admin/src/styles/hunt-insights.css`:

```css
/* ============================================================================
   Business Hunt dashboard. Builds on insights.css (shared chart components
   keep their .ins-* classes); this file adds only the Hunt-specific pieces.
   ========================================================================== */

/* --- Needs-attention chips ------------------------------------------------- */
.hi-attention {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.hi-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 13px;
  border-radius: var(--r-lg);
  border: 1px solid var(--border-1);
  background: var(--surface-1);
  backdrop-filter: blur(12px);
  text-decoration: none;
  color: var(--text-1);
  font-size: 13px;
  transition: border-color .15s ease, transform .15s ease;
}
.hi-chip:hover { border-color: var(--border-3); transform: translateY(-1px); }
.hi-chip-n {
  font-weight: 700;
  font-size: 15px;
  font-variant-numeric: tabular-nums;
}
.hi-chip.urgent .hi-chip-n { color: var(--danger); }
.hi-chip.warn   .hi-chip-n { color: var(--warn); }

/* --- Funnel bars ----------------------------------------------------------- */
.hi-funnel { display: flex; flex-direction: column; gap: 10px; }
.hi-fn-row { display: flex; flex-direction: column; gap: 4px; }
.hi-fn-head { display: flex; justify-content: space-between; font-size: 12px; }
.hi-fn-lab { color: var(--text-2); text-transform: capitalize; }
.hi-fn-val { font-variant-numeric: tabular-nums; color: var(--text-1); font-weight: 600; }
.hi-fn-track {
  height: 8px;
  border-radius: 99px;
  background: var(--neutral-soft);
  overflow: hidden;
}
.hi-fn-fill { height: 100%; border-radius: 99px; transition: width .3s ease; }

/* --- Agent leaderboard ----------------------------------------------------- */
.hi-board-scroll { overflow-x: auto; }
.hi-board { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 380px; }
.hi-board th {
  text-align: right;
  font-weight: 500;
  color: var(--text-2);
  padding: 6px 8px;
  border-bottom: 1px solid var(--border-1);
  white-space: nowrap;
}
.hi-board th:first-child, .hi-board td:first-child { text-align: left; }
.hi-board td {
  text-align: right;
  padding: 9px 8px;
  border-bottom: 1px solid var(--border-1);
  font-variant-numeric: tabular-nums;
}
.hi-board tr:last-child td { border-bottom: 0; }
.hi-board-name { font-weight: 600; color: var(--text-1); }

/* --- Credits --------------------------------------------------------------- */
.hi-credits { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.hi-cr-big { display: flex; flex-direction: column; }
.hi-cr-num { font-size: 30px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.hi-cr-cap { font-size: 12px; color: var(--text-2); margin-top: 4px; }
.hi-cr-meta { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: var(--text-2); text-align: right; }
.hi-cr-buy {
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  color: var(--mint-300);
  white-space: nowrap;
}
```

- [ ] **Step 4: Create the page**

Create `admin/src/pages/HuntInsights.tsx`:

```tsx
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { ChartCard } from '@/components/charts/ChartCard';
import { Donut } from '@/components/charts/Donut';
import { Kpi, Delta } from '@/components/charts/Kpi';
import { RangeFilter } from '@/components/charts/RangeFilter';
import { TrendChart } from '@/components/charts/TrendChart';
import { useShop } from '@/context/ShopContext';
import {
  daysBetween, fmtNum, pctChange, presetRange, previousRange, type PresetKey,
} from '@/lib/dateRange';
import { getHuntInsights, type HuntInsights as Data } from '@/lib/huntInsights';
import type { LeadStatus } from '@/types';
import '@/styles/insights.css';
import '@/styles/hunt-insights.css';

/* Funnel stage colours, warm at the top of the pipeline to mint at the win. */
const STAGE_COLOR: Record<LeadStatus, string> = {
  new: 'var(--info)',
  sent: 'var(--info)',
  followup: 'var(--warn)',
  replied: 'var(--mint-300)',
  demo: 'var(--mint-300)',
  won: 'var(--mint-300)',
  pass: 'var(--neutral-soft)',
};

const STAGE_LABEL: Record<LeadStatus, string> = {
  new: 'New', sent: 'Contacted', followup: 'Following up', replied: 'Replied',
  demo: 'Demo', won: 'Won', pass: 'Passed',
};

const AED = (n: number) => `AED ${fmtNum(Math.round(n))}`;

/* ---------- needs attention ------------------------------------------------ */
function Attention({ a }: { a: Data['attention'] }) {
  const chips = [
    { key: 'overdue', n: a.followups_overdue, label: 'Overdue', to: '/leads?followups=overdue', cls: 'urgent' },
    { key: 'today', n: a.followups_today, label: 'Due today', to: '/leads?followups=today', cls: 'warn' },
    { key: 'stale', n: a.stale, label: 'Going cold', to: '/leads?stale=1', cls: 'warn' },
    // Always 0 for an agent — AssignedLeadScope makes unassigned leads
    // unreachable for them — so this simply never renders on their dashboard.
    { key: 'unassigned', n: a.unassigned, label: 'Unassigned', to: '/leads?assigned_to=unassigned', cls: '' },
  ].filter((c) => c.n > 0);

  if (chips.length === 0) {
    return <div className="ins-empty"><span className="ins-empty-txt">Nothing needs chasing right now.</span></div>;
  }

  return (
    <div className="hi-attention">
      {chips.map((c) => (
        <Link key={c.key} className={`hi-chip ${c.cls}`} to={c.to}>
          <span className="hi-chip-n">{fmtNum(c.n)}</span>
          <span>{c.label}</span>
        </Link>
      ))}
    </div>
  );
}

/* ---------- funnel --------------------------------------------------------- */
function Funnel({ pipeline }: { pipeline: Record<LeadStatus, number> }) {
  const order: LeadStatus[] = ['new', 'sent', 'followup', 'replied', 'demo', 'won', 'pass'];
  const max = Math.max(1, ...order.map((s) => pipeline[s]));
  const total = order.reduce((sum, s) => sum + pipeline[s], 0);

  if (total === 0) {
    return <div className="ins-empty"><span className="ins-empty-txt">No leads saved yet.</span></div>;
  }

  return (
    <div className="hi-funnel">
      {order.map((s) => (
        <div key={s} className="hi-fn-row">
          <div className="hi-fn-head">
            <span className="hi-fn-lab">{STAGE_LABEL[s]}</span>
            <span className="hi-fn-val">{fmtNum(pipeline[s])}</span>
          </div>
          <div className="hi-fn-track">
            <div className="hi-fn-fill" style={{ width: `${(pipeline[s] / max) * 100}%`, background: STAGE_COLOR[s] }} />
          </div>
        </div>
      ))}
    </div>
  );
}

/* ---------- leaderboard ---------------------------------------------------- */
function Leaderboard({ rows }: { rows: Data['agents'] }) {
  return (
    <div className="hi-board-scroll">
      <table className="hi-board">
        <thead>
          <tr><th>Agent</th><th>Leads</th><th>Won</th><th>Value</th></tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id}>
              <td className="hi-board-name">{r.name}</td>
              <td>{fmtNum(r.leads)}</td>
              <td>{fmtNum(r.won)}</td>
              <td>{AED(r.won_value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* ---------- skeleton ------------------------------------------------------- */
function Skeleton() {
  return (
    <>
      <div className="ins-kpis">{[0, 1, 2, 3].map((i) => <div key={i} className="ins-skel" style={{ height: 92 }} />)}</div>
      <div className="ins-skel" style={{ height: 240 }} />
      <div className="ins-grid">
        <div className="ins-skel" style={{ height: 200 }} />
        <div className="ins-skel" style={{ height: 200 }} />
      </div>
    </>
  );
}

/* ---------- page ----------------------------------------------------------- */
export default function HuntInsights() {
  const { shop } = useShop();
  const today = useMemo(() => new Date(), []);

  const [preset, setPreset] = useState<PresetKey>('30d');
  const initial = useMemo(() => presetRange('30d', today), [today]);
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);

  const [data, setData] = useState<Data | null>(null);
  const [prev, setPrev] = useState<Data | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const choosePreset = (key: Exclude<PresetKey, 'custom'>) => {
    const r = presetRange(key, today);
    setPreset(key); setFrom(r.from); setTo(r.to);
  };

  // Normalised, so an inverted custom range still behaves.
  const nf = from <= to ? from : to;
  const nt = from <= to ? to : from;

  const fetchData = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true); setError('');
    const p = previousRange(nf, nt);
    try {
      const [cur, previous] = await Promise.allSettled([
        getHuntInsights(nf, nt),
        getHuntInsights(p.from, p.to),
      ]);
      if (cur.status === 'rejected') throw cur.reason;
      setData(cur.value);
      setPrev(previous.status === 'fulfilled' ? previous.value : null);
    } catch {
      setError('Could not load your Hunt stats.');
      setData(null); setPrev(null);
    } finally {
      setLoading(false);
    }
  }, [shop?.id, nf, nt]);

  useEffect(() => { void fetchData(); }, [fetchData]);

  const rangeLen = daysBetween(nf, nt);

  /** Delta node for a plain count/amount KPI. */
  const delta = (cur: number, prior: number | undefined, fmt: (n: number) => string) => {
    const change = prior === undefined ? null : pctChange(cur, prior);
    return <Delta change={change} display={change === null ? '' : `${Math.abs(Math.round(change))}%`} goodDir="up" />;
  };

  const s = data?.summary;
  const ps = prev?.summary;

  // The one honest ratio available: of leads that actually reached a decision,
  // how many went our way. A period-wins ÷ period-leads figure would be
  // nonsense, since most wins come from leads created before the period.
  const decided = s ? s.pipeline.won + s.pipeline.pass : 0;
  const winRate = decided > 0 ? Math.round((s!.pipeline.won / decided) * 100) : null;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Hunt stats</h1>
        <p className="c-page-sub">Your pipeline, your wins and what needs chasing.</p>
      </div>

      <div className="ins-wrap">
        <RangeFilter
          preset={preset} from={from} to={to}
          onPreset={choosePreset}
          onFrom={(v) => { setFrom(v); setPreset('custom'); }}
          onTo={(v) => { setTo(v); setPreset('custom'); }}
        />

        {error && <div className="c-error-box">{error}</div>}

        {loading ? <Skeleton /> : data && s ? (
          <>
            <div className="ins-kpis">
              <Kpi label="New leads" value={fmtNum(s.new_leads)} delta={delta(s.new_leads, ps?.new_leads, fmtNum)} />
              <Kpi label="Deals won" value={fmtNum(s.won)} delta={delta(s.won, ps?.won, fmtNum)} />
              <Kpi label="Won value" value={AED(s.won_value)} delta={delta(s.won_value, ps?.won_value, AED)} />
              <Kpi label="MRR won" value={AED(s.mrr_won)} delta={delta(s.mrr_won, ps?.mrr_won, AED)} />
            </div>

            <ChartCard icon="Bell" title="Needs attention" sub="Right now — not affected by the date range">
              <Attention a={data.attention} />
            </ChartCard>

            <ChartCard icon="Chart" title="Leads & wins over time"
              sub={rangeLen > 62 ? 'Weekly totals' : 'Daily totals'} span2>
              <TrendChart
                emptyText="No lead activity in this range yet."
                series={[
                  { key: 'leads', label: 'new leads', color: 'var(--info)', points: data.daily.map((d) => ({ date: d.date, value: d.leads })) },
                  { key: 'won', label: 'deals won', color: 'var(--mint-300)', points: data.daily.map((d) => ({ date: d.date, value: d.won })) },
                ]}
              />
            </ChartCard>

            <div className="ins-grid">
              <ChartCard icon="List" title="Pipeline"
                sub={winRate === null ? 'No decided leads yet' : `${winRate}% of decided leads won`}>
                <Funnel pipeline={s.pipeline} />
              </ChartCard>

              <ChartCard icon="Tag" title="Deal mix" sub="Where the won value came from">
                <Donut cap="Won" emptyText="No won value in this range yet." segments={[
                  { key: 'recurring', label: 'Recurring', value: Math.round(s.won_value_recurring), color: 'var(--mint-300)' },
                  { key: 'one_off', label: 'One-off', value: Math.round(s.won_value_one_off), color: 'var(--info)' },
                ]} />
              </ChartCard>

              {data.agents.length > 0 && (
                <ChartCard icon="Users" title="Agent leaderboard" sub="Wins in this range, best first">
                  <Leaderboard rows={data.agents} />
                </ChartCard>
              )}

              <ChartCard icon="Search" title="Credits" sub="1 credit = one live search">
                <div className="hi-credits">
                  <span className="hi-cr-big">
                    <span className="hi-cr-num">{fmtNum(data.credits.balance)}</span>
                    <span className="hi-cr-cap">credits left</span>
                  </span>
                  <span className="hi-cr-meta">
                    <span>{fmtNum(data.credits.used)} used in this range</span>
                    <span>{fmtNum(data.credits.searches)} searches run</span>
                  </span>
                  <Link className="hi-cr-buy" to="/leads/credits">Buy credits <Icons.ArrowRight size={13} /></Link>
                </div>
              </ChartCard>
            </div>
          </>
        ) : null}
      </div>
    </div></div>
  );
}
```

- [ ] **Step 5: Run the test**

```bash
cd admin && npx vitest run src/pages/HuntInsights.test.tsx
```

Expected: PASS (8 tests).

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/HuntInsights.tsx admin/src/pages/HuntInsights.test.tsx admin/src/styles/hunt-insights.css
git commit -m "feat(admin): Business Hunt dashboard page"
```

---

## Task 10: Route + navigation

**Files:**
- Modify: `admin/src/App.tsx`, `admin/src/layout/DesktopSidebar.tsx`, `admin/src/lib/nav.ts`
- Test: `admin/src/layout/DesktopSidebar.test.tsx`, `admin/src/lib/nav.test.ts` (create if absent)

**Interfaces:**
- Consumes: `HuntInsights` page (Task 9).
- Produces: `/hunt-insights` reachable, gated by `module:leads` + `leads.view`, listed in the desktop rail and the Settings list.

- [ ] **Step 1: Write the failing tests**

Append to `admin/src/layout/DesktopSidebar.test.tsx`, inside the existing `describe`:

```tsx
  it('shows Hunt Stats for a leads shop and hides it for a bookings shop', () => {
    renderWith(['leads']);
    expect(screen.getByText('Hunt Stats')).toBeTruthy();
  });

  it('hides Hunt Stats from a bookings-only shop', () => {
    renderWith(['bookings']);
    expect(screen.queryByText('Hunt Stats')).toBeNull();
  });
```

Create `admin/src/lib/nav.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { visibleSettingsOptions, visibleSettingsPages } from './nav';
import type { Shop } from '@/types';

const leadsShop = { name: 'S', modules: ['leads'] } as unknown as Shop;
const bookingsShop = { name: 'S', modules: ['bookings'] } as unknown as Shop;
const all = () => true;

describe('nav — Hunt Stats entry', () => {
  it('is offered to a leads shop with leads.view', () => {
    const labels = visibleSettingsOptions(leadsShop, all).map((o) => o.label);
    expect(labels).toContain('Hunt Stats');
  });

  it('is hidden from a bookings-only shop', () => {
    const labels = visibleSettingsOptions(bookingsShop, all).map((o) => o.label);
    expect(labels).not.toContain('Hunt Stats');
  });

  it('is hidden without leads.view', () => {
    const labels = visibleSettingsOptions(leadsShop, (p) => p !== 'leads.view').map((o) => o.label);
    expect(labels).not.toContain('Hunt Stats');
  });

  it('is a shortcut, so it never makes an otherwise-empty Settings menu appear', () => {
    const pages = visibleSettingsPages(leadsShop, all).map((o) => o.label);
    expect(pages).not.toContain('Hunt Stats');
  });
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd admin && npx vitest run src/layout/DesktopSidebar.test.tsx src/lib/nav.test.ts
```

Expected: FAIL — "Hunt Stats" is nowhere.

- [ ] **Step 3: Add the route**

In `admin/src/App.tsx`, add the import next to the other page imports:

```tsx
import HuntInsights from '@/pages/HuntInsights';
```

and add the route inside the existing `RequirePerm perm="leads.view"` block, after `/leads/credits`:

```tsx
              <Route path="/hunt-insights" element={<HuntInsights />} />
```

- [ ] **Step 4: Add the rail item**

In `admin/src/layout/DesktopSidebar.tsx`, add to `BASE_NAV` immediately after the Business Hunt entry:

```tsx
  { label: 'Hunt Stats', to: '/hunt-insights', icon: 'Chart', modules: ['leads'], perm: 'leads.view' },
```

- [ ] **Step 5: Add the Settings entry**

In `admin/src/lib/nav.ts`, add to `ALL_SETTINGS_OPTIONS` immediately after the Business Hunt entry:

```ts
  { label: 'Hunt Stats', sub: 'Pipeline, wins & what needs chasing', to: '/hunt-insights', icon: 'Chart', modules: ['leads'], perm: 'leads.view', shortcut: true },
```

`shortcut: true` is required: it is already a top-level rail item, so it must not be what makes a Settings menu appear for someone who has nothing else in Settings.

- [ ] **Step 6: Run the tests**

```bash
cd admin && npx vitest run src/layout/DesktopSidebar.test.tsx src/lib/nav.test.ts
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add admin/src/App.tsx admin/src/layout/DesktopSidebar.tsx admin/src/lib/nav.ts admin/src/layout/DesktopSidebar.test.tsx admin/src/lib/nav.test.ts
git commit -m "feat(admin): route + nav entries for the Hunt dashboard"
```

---

## Task 11: Leads page reads its filters from the URL

**Files:**
- Modify: `admin/src/pages/Leads.tsx` (`PipelinePane`)
- Test: `admin/src/pages/Leads.assign.test.tsx`

**Interfaces:**
- Consumes: the `followups`/`stale`/`assigned_to`/`status` query parameters produced by the attention chips (Task 9) and honoured by the API (Task 4).
- Produces: `PipelinePane` seeds `statusFilter`, `dueOnly`, `staleOnly`, `ownerFilter` from the URL on mount, and passes `followups`/`stale` through to `listLeads`.

This is deep-linking, not two-way binding: the URL seeds the initial state and is not rewritten as the user changes filters afterwards.

- [ ] **Step 1: Write the failing test**

Append to `admin/src/pages/Leads.assign.test.tsx` (it already renders the Leads page with a router; match its existing `setup` helper's signature — if it renders with `<MemoryRouter>` and no `initialEntries`, add the parameter):

```tsx
  it('opens pre-filtered when the URL carries a followups filter', async () => {
    const spy = vi.spyOn(leadsLib, 'listLeads').mockResolvedValue({
      data: [], funnel: { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 },
      pipelines: [], won_value: 0, assignees: [], auto_assign: false,
    });

    render(
      <MemoryRouter initialEntries={['/leads?followups=overdue']}>
        <ShopProvider><Leads /></ShopProvider>
      </MemoryRouter>,
    );

    await waitFor(() => expect(spy).toHaveBeenCalled());
    expect(spy.mock.calls.at(-1)?.[0]).toMatchObject({ followups: 'overdue' });
  });

  it('opens pre-filtered on stale', async () => {
    const spy = vi.spyOn(leadsLib, 'listLeads').mockResolvedValue({
      data: [], funnel: { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 },
      pipelines: [], won_value: 0, assignees: [], auto_assign: false,
    });

    render(
      <MemoryRouter initialEntries={['/leads?stale=1']}>
        <ShopProvider><Leads /></ShopProvider>
      </MemoryRouter>,
    );

    await waitFor(() => expect(spy).toHaveBeenCalled());
    expect(spy.mock.calls.at(-1)?.[0]).toMatchObject({ stale: true });
  });

  it('opens pre-filtered on unassigned', async () => {
    const spy = vi.spyOn(leadsLib, 'listLeads').mockResolvedValue({
      data: [], funnel: { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 },
      pipelines: [], won_value: 0, assignees: [], auto_assign: false,
    });

    render(
      <MemoryRouter initialEntries={['/leads?assigned_to=unassigned']}>
        <ShopProvider><Leads /></ShopProvider>
      </MemoryRouter>,
    );

    await waitFor(() => expect(spy).toHaveBeenCalled());
    expect(spy.mock.calls.at(-1)?.[0]).toMatchObject({ assigned_to: 'unassigned' });
  });
```

Ensure the file imports `waitFor` from `@testing-library/react` and `MemoryRouter` from `react-router-dom`.

The Leads page opens on the "find" tab by default. If these tests fail because `PipelinePane` never mounts, add `?tab=pipeline` handling in Step 3 — see the note there.

- [ ] **Step 2: Run them to verify they fail**

```bash
cd admin && npx vitest run src/pages/Leads.assign.test.tsx
```

Expected: FAIL — `listLeads` is called with no `followups`/`stale` key.

- [ ] **Step 3: Seed the filters from the URL**

In `admin/src/pages/Leads.tsx`:

1. Add `useSearchParams` to the `react-router-dom` import.
2. In the top-level `Leads` component, make the mode default to pipeline whenever a filter parameter is present — otherwise a chip would land the user on the search tab:

```tsx
  const [params] = useSearchParams();
  const deepLinked = params.has('followups') || params.has('stale') || params.has('assigned_to') || params.has('status');
  const [mode, setMode] = useState<Mode>(deepLinked ? 'pipeline' : 'find');
```

(Replace the existing `useState<Mode>('find')`; keep the rest of that component as-is.)

3. In `PipelinePane`, replace the filter state initialisers with URL-seeded ones:

```tsx
  const [params] = useSearchParams();
  // The dashboard's attention chips deep-link in here. This seeds the initial
  // state only — the URL is not rewritten as the user changes filters after.
  const [statusFilter, setStatusFilter] = useState<LeadStatus | null>(
    () => (params.get('status') as LeadStatus | null) ?? null,
  );
  const [dueOnly, setDueOnly] = useState(() => params.get('followups') === 'due');
  const [followupFilter, setFollowupFilter] = useState<'overdue' | 'today' | null>(() => {
    const f = params.get('followups');
    return f === 'overdue' || f === 'today' ? f : null;
  });
  const [staleOnly, setStaleOnly] = useState(() => params.get('stale') === '1');
```

and seed the owner filter:

```tsx
  const [ownerFilter, setOwnerFilter] = useState<'me' | 'unassigned' | number | null>(() => {
    const a = params.get('assigned_to');
    if (a === 'me' || a === 'unassigned') return a;
    return a ? Number(a) : null;
  });
```

4. Pass the new filters to `listLeads` inside `fetch`:

```tsx
        followups: followupFilter ?? (dueOnly ? 'due' : undefined),
        stale: staleOnly || undefined,
```

5. Add `followupFilter` and `staleOnly` to both the `fetch` `useCallback` dependency array and the page-reset `useEffect` dependency array (the one calling `setPage(1)`).

6. So a deep-linked filter is visible and clearable, render a dismiss chip just above the list, next to the existing filter controls:

```tsx
        {(followupFilter || staleOnly) && (
          <button className="lf-chip on" onClick={() => { setFollowupFilter(null); setStaleOnly(false); }}>
            {followupFilter === 'overdue' ? 'Overdue follow-ups'
              : followupFilter === 'today' ? 'Due today'
              : 'Going cold'} ✕
          </button>
        )}
```

- [ ] **Step 4: Run the tests**

```bash
cd admin && npx vitest run src/pages/Leads.assign.test.tsx
```

Expected: PASS.

- [ ] **Step 5: Run the full frontend suite**

```bash
cd admin && npx vitest run
```

Expected: PASS, no regressions.

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/Leads.tsx admin/src/pages/Leads.assign.test.tsx
git commit -m "feat(admin): Leads page opens pre-filtered from dashboard chips"
```

---

## Task 12: Full verification and deploy

**Files:** none changed — this task ships what the previous eleven built.

- [ ] **Step 1: Full backend suite on the droplet**

```bash
bash ".../scratchpad/synctest.sh"
```

Expected: PASS. 596 pre-existing + 16 new = 612 or more. **Zero failures.** If anything fails, stop and fix before deploying.

- [ ] **Step 2: Full frontend suite + typecheck + build**

```bash
cd admin && npx vitest run && npx tsc --noEmit && npm run build
```

Expected: all pass, build succeeds.

- [ ] **Step 3: Push**

```bash
git push origin main
```

- [ ] **Step 4: Deploy the backend to staging**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && git fetch -q && git reset -q --hard origin/main && php artisan optimize:clear && php artisan route:cache'
```

There is no migration in this change, so no `migrate` step. Confirm with:

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && php artisan migrate:status | tail -5'
```

Expected: no pending migrations.

- [ ] **Step 5: Smoke-test staging**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://staging-api.eloquentservice.com/api/shop/reports/hunt?from=2026-07-01\&to=2026-07-31
```

Expected: `401` — the route exists and is auth-gated. A `404` means the route cache did not rebuild.

- [ ] **Step 6: Deploy the admin frontend to staging, then verify in a browser**

Use `admin/deploy.ps1` (staging target). Then open `/hunt-insights` as a shop with the leads module and confirm: KPIs render, the trend chart draws two lines, the attention chips navigate to a correctly filtered Leads list, and the leaderboard appears.

- [ ] **Step 7: Promote to prod**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && git fetch -q && git reset -q --hard origin/main && php artisan optimize:clear && php artisan route:cache'
```

Then `admin/deploy.ps1` for the prod admin bundle.

- [ ] **Step 8: Verify prod**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.eloquentservice.com/api/shop/reports/hunt?from=2026-07-01\&to=2026-07-31
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php artisan migrate:status | tail -3'
```

Expected: `401` on the endpoint, no pending migrations. Then load `/hunt-insights` in the prod admin and confirm the numbers match what the Leads page shows.

- [ ] **Step 9: Record it**

Add a memory file `hunt-dashboard.md` noting the page is live, the endpoint, and that `Lead`'s follow-up/stale scopes are the shared definition behind both the counts and the list filters. Add the one-line pointer to `MEMORY.md`.

---

## Self-Review

**1. Spec coverage**

| Spec section | Task |
|---|---|
| §1.1 endpoint + route group | 5 |
| §1.2 response shape | 5 |
| §1.3 `huntDaily` | 2 |
| §1.4 `huntAttention` | 3 |
| §1.5 Eloquent vs raw + class comment | 2 (step 4), 3 |
| §1.6 shared scopes + index filters | 3, 4 |
| §1.7 `dealTotal` | 1 |
| §2 page sections 1-8 | 9 |
| §2.1 navigation | 10 |
| §2.2 URL-seeded Leads filters | 11 |
| §3 chart extraction + `TrendChart` N-series | 7 |
| §3.1 characterization-first mitigation | 6 |
| §4 backend tests | 2, 3, 4, 5 |
| §4 frontend tests | 6, 7, 9, 10, 11 |
| §5 rollout | 12 |

No gaps.

**2. Placeholder scan**

No TBDs. Every code step carries the actual code. Task 11 Step 1 flags a conditional ("if these fail because `PipelinePane` never mounts") but resolves it concretely in Step 3 with the `deepLinked` mode default, so it is a stated dependency rather than a hole.

**3. Type consistency**

- `dealTotal(mixed, mixed, mixed): ?float` — defined Task 1, used Tasks 1 and 2.
- `huntDaily(int, Carbon, Carbon): array` with keys `date/leads/won/won_value` — Task 2, consumed by Task 5 and mirrored by `HuntDaily` in Task 8 and the test payload in Task 9.
- `huntAttention(int): array` with keys `followups_overdue/followups_today/stale/unassigned` — Task 3, consumed by Task 5, typed as `HuntAttention` in Task 8, rendered in Task 9, and the chip URLs in Task 9 match the parameters implemented in Task 4 (`followups=overdue`, `followups=today`, `stale=1`, `assigned_to=unassigned`).
- `TrendChart({series, emptyText})` — defined Task 7, used by the rewired `Insights.tsx` in the same task and by `HuntInsights.tsx` in Task 9, both passing the `{key,label,color,points}` shape.
- `Donut({segments, cap, emptyText})` — the `emptyText` prop is added in Task 7 and passed by both call sites.
- `previousRange(from, to)` — defined Task 7, used by `Insights.tsx` (Task 7) and `HuntInsights.tsx` (Task 9).
- Nav label `'Hunt Stats'` and path `/hunt-insights` are identical across Task 10's route, rail item, settings entry and both test files.
