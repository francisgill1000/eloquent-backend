# Product-aware AI dashboard summary — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the dashboard AI summary product-aware — summarize a shop on whichever module(s) it has (`bookings` and/or `leads`), with a per-product min-data gate, and pre-generate for Hunt shops in the nightly job.

**Architecture:** A new `ReportsAggregator::huntSummary()` produces pipeline metrics. `AiInsightsWriter` loads the shop's modules, gates each product independently, and builds a payload with a `bookings` block and/or a `hunt` block. `GenerateDailyAiSummaries` iterates the union of shops with bookings or Hunt activity in the window. No DB schema change; no frontend change.

**Tech Stack:** Laravel (PHP 8.4), Eloquent, query builder, PHPUnit, `Http::fake` for the Anthropic call.

## Global Constraints

- **Tests run on the droplet only** (php8.4), against the scratch/sqlite harness — never local, never prod (memories `run-tests-on-droplet`, `never-run-tests-against-prod-db`). Harness: `bash "C:/Users/franc/AppData/Local/Temp/claude/d--Francis-projects-2026-Eloquent-Solutions-Business-Lens/60d12e62-82b5-4d57-a7a4-5a66113a9f3c/scratchpad/droplet-test.sh" <Filter>`.
- **Cross-DB portability:** tests use sqlite, prod uses Postgres. Do NOT use DB-specific JSON SQL (`payload->>'to'`, `json_extract`). Read `lead_activities.payload` and aggregate the target status in PHP.
- **Multi-tenant:** every query scoped to `$shopId`. `lead_activities` has NO `shop_id` — join through `leads` on `lead_id`.
- **Preserve existing behaviour** for bookings-only shops: the existing `AiInsightsWriterTest`, `GenerateDailyAiSummariesTest`, `AiInsightsEndpointTest` must stay green.
- Work directly on `main`; commit after each task (memory `no-feature-branches`). Do NOT deploy — promotion is a separate explicit step.
- `Lead::STATUSES = ['new','sent','replied','demo','won','pass']`. Search debits are `hunt_credit_transactions.reason = 'search'` with negative `amount`.

---

### Task 1: `ReportsAggregator::huntSummary()`

Add a tenant-scoped Hunt pipeline metrics method.

**Files:**
- Modify: `app/Services/Reports/ReportsAggregator.php`
- Test: `tests/Feature/ReportsAggregatorHuntTest.php` (create)

**Interfaces:**
- Produces: `ReportsAggregator::huntSummary(int $shopId, Carbon $from, Carbon $to): array` with keys `range, new_leads, pipeline (status=>int over Lead::STATUSES), total_leads, moved (status=>int), won, credits_used, searches`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReportsAggregatorHuntTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Reports\ReportsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsAggregatorHuntTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'H' . $code, 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
    }

    public function test_hunt_summary_reports_pipeline_movement_and_activity(): void
    {
        $shop = $this->shop('8001');

        // Pipeline snapshot: 3 new, 1 won.
        Lead::create(['shop_id' => $shop->id, 'name' => 'A', 'status' => 'new']);
        Lead::create(['shop_id' => $shop->id, 'name' => 'B', 'status' => 'new']);
        Lead::create(['shop_id' => $shop->id, 'name' => 'C', 'status' => 'new']);
        $won = Lead::create(['shop_id' => $shop->id, 'name' => 'D', 'status' => 'won']);

        // Movement in period: two →sent, one →won.
        $won->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'demo', 'to' => 'won']]);
        Lead::find($won->id); // no-op, keep style
        foreach (['A', 'B'] as $n) {
            $lead = Lead::where('shop_id', $shop->id)->where('name', $n)->first();
            $lead->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'new', 'to' => 'sent']]);
        }

        // A non-status activity must be ignored.
        $won->activities()->create(['type' => 'contacted', 'payload' => ['channel' => 'whatsapp']]);

        // Credits used (2 search debits) + a grant that must NOT count.
        DB::table('hunt_credit_transactions')->insert([
            ['shop_id' => $shop->id, 'amount' => -1, 'reason' => 'search', 'balance_after' => 9, 'created_at' => now(), 'updated_at' => now()],
            ['shop_id' => $shop->id, 'amount' => -1, 'reason' => 'search', 'balance_after' => 8, 'created_at' => now(), 'updated_at' => now()],
            ['shop_id' => $shop->id, 'amount' => 50, 'reason' => 'grant', 'balance_after' => 58, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Two searches logged.
        DB::table('lead_search_logs')->insert([
            ['shop_id' => $shop->id, 'query' => 'gyms', 'created_at' => now()],
            ['shop_id' => $shop->id, 'query' => 'hotels', 'created_at' => now()],
        ]);

        $out = app(ReportsAggregator::class)->huntSummary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(4, $out['new_leads']);       // all 4 leads created this month
        $this->assertSame(3, $out['pipeline']['new']);
        $this->assertSame(1, $out['pipeline']['won']);
        $this->assertSame(4, $out['total_leads']);
        $this->assertSame(2, $out['moved']['sent']);
        $this->assertSame(1, $out['moved']['won']);
        $this->assertSame(1, $out['won']);
        $this->assertSame(2, $out['credits_used']);    // abs of the two -1 search debits
        $this->assertSame(2, $out['searches']);
    }

    public function test_hunt_summary_is_tenant_scoped(): void
    {
        $a = $this->shop('8002');
        $b = $this->shop('8003');
        Lead::create(['shop_id' => $b->id, 'name' => 'other', 'status' => 'new'])
            ->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'new', 'to' => 'won']]);

        $out = app(ReportsAggregator::class)->huntSummary($a->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(0, $out['new_leads']);
        $this->assertSame(0, $out['total_leads']);
        $this->assertSame(0, $out['moved']['won']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash ".../droplet-test.sh" ReportsAggregatorHuntTest`
Expected: FAIL — `huntSummary()` does not exist.

- [ ] **Step 3: Implement `huntSummary()`**

In `app/Services/Reports/ReportsAggregator.php`, add `use App\Models\Lead;` at the top (alongside `use App\Models\Booking;`), then add this method (e.g. after `insightsSummary`):

```php
    /**
     * Business Hunt pipeline metrics for a shop over a date range. Tenant-scoped
     * via leads.shop_id (lead_activities has no shop_id — joined through leads).
     * `pipeline`/`total_leads` are a CURRENT snapshot; the rest are period-bound.
     */
    public function huntSummary(int $shopId, Carbon $from, Carbon $to): array
    {
        $statuses = Lead::STATUSES;

        // Current pipeline snapshot (not date-bounded), zero-filled.
        $pipeline = array_fill_keys($statuses, 0);
        foreach (
            DB::table('leads')->where('shop_id', $shopId)
                ->selectRaw('status, count(*) as c')->groupBy('status')
                ->pluck('c', 'status') as $st => $c
        ) {
            if (array_key_exists($st, $pipeline)) {
                $pipeline[$st] = (int) $c;
            }
        }

        // Leads created in the period.
        $newLeads = (int) DB::table('leads')->where('shop_id', $shopId)
            ->whereBetween('created_at', [$from, $to])->count();

        // Status changes in the period, aggregated by target status IN PHP
        // (portable across sqlite/pgsql — no JSON SQL).
        $moved = array_fill_keys($statuses, 0);
        $payloads = DB::table('lead_activities')
            ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->where('leads.shop_id', $shopId)
            ->where('lead_activities.type', 'status_change')
            ->whereBetween('lead_activities.created_at', [$from, $to])
            ->pluck('lead_activities.payload');
        foreach ($payloads as $payload) {
            $data = is_array($payload) ? $payload : json_decode((string) $payload, true);
            $target = is_array($data) ? ($data['to'] ?? null) : null;
            if (is_string($target) && array_key_exists($target, $moved)) {
                $moved[$target]++;
            }
        }

        // Credits spent on live searches (search debits are negative).
        $creditsUsed = (int) abs((int) DB::table('hunt_credit_transactions')
            ->where('shop_id', $shopId)->where('reason', 'search')
            ->whereBetween('created_at', [$from, $to])->sum('amount'));

        // Searches logged in the period.
        $searches = (int) DB::table('lead_search_logs')->where('shop_id', $shopId)
            ->whereBetween('created_at', [$from, $to])->count();

        return [
            'range'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'new_leads'    => $newLeads,
            'pipeline'     => $pipeline,
            'total_leads'  => array_sum($pipeline),
            'moved'        => $moved,
            'won'          => $moved['won'],
            'credits_used' => $creditsUsed,
            'searches'     => $searches,
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bash ".../droplet-test.sh" ReportsAggregatorHuntTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/ReportsAggregator.php tests/Feature/ReportsAggregatorHuntTest.php
git commit -m "feat(reports): add Hunt pipeline metrics (huntSummary)"
```

---

### Task 2: Product-aware `AiInsightsWriter`

Gate and build the payload per product; generalize the prompt.

**Files:**
- Modify: `app/Services/Reports/AiInsightsWriter.php`
- Test: `tests/Feature/AiInsightsWriterTest.php` (extend)

**Interfaces:**
- Consumes: `ReportsAggregator::huntSummary()` (Task 1), `Shop::hasModule()`, `Shop::$is_master`.
- Produces: `summary()` (unchanged signature) now returns `ok` for a leads shop that clears the Hunt gate, with a `hunt` block in the model payload; `low_data` (with a Hunt-appropriate message) otherwise.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AiInsightsWriterTest.php` these helpers and tests (the file already has `fakeClaude`, `writer`, `shop`, `seedBookings`, and imports `Shop`, `Http`, `DB` via `\DB`):

```php
    private function leadsShop(string $code = '7101'): Shop
    {
        return Shop::create([
            'name' => 'Hunt Co', 'shop_code' => $code, 'pin' => '0000',
            'status' => 'active', 'category_id' => 11, 'modules' => ['leads'],
        ]);
    }

    /** Seed $count leads + a status_change activity each (counts as Hunt actions). */
    private function seedHuntActivity(Shop $shop, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $lead = \App\Models\Lead::create(['shop_id' => $shop->id, 'name' => "L{$i}", 'status' => 'sent']);
            $lead->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'new', 'to' => 'sent']]);
        }
    }

    public function test_leads_shop_with_activity_generates_with_a_hunt_block(): void
    {
        $shop = $this->leadsShop();
        $this->seedHuntActivity($shop, 6); // 6 new + 6 moves = 12 actions >= 5
        $this->fakeClaude(['summary' => 'Pipeline is growing.', 'patterns' => ['a'], 'recommendations' => ['b']]);

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('ok', $out['state']);
        // The model payload must carry a hunt section and no bookings section.
        Http::assertSent(fn ($req) => str_contains($req->body(), '"hunt"') && ! str_contains($req->body(), '"bookings"'));
    }

    public function test_leads_shop_without_activity_is_low_data_with_hunt_message(): void
    {
        $shop = $this->leadsShop('7102');
        $this->seedHuntActivity($shop, 2); // 4 actions < 5
        Http::fake();

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('low_data', $out['state']);
        $this->assertStringContainsStringIgnoringCase('hunt', $out['message']);
        Http::assertNothingSent();
    }

    public function test_mixed_shop_clearing_both_gates_sends_both_blocks(): void
    {
        $shop = Shop::create([
            'name' => 'Both', 'shop_code' => '7103', 'pin' => '0000',
            'status' => 'active', 'category_id' => 11, 'modules' => ['bookings', 'leads'],
        ]);
        $this->seedBookings($shop, 6);
        $this->seedHuntActivity($shop, 6);
        $this->fakeClaude(['summary' => 'Both sides healthy.', 'patterns' => ['a'], 'recommendations' => ['b']]);

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('ok', $out['state']);
        Http::assertSent(fn ($req) => str_contains($req->body(), '"bookings"') && str_contains($req->body(), '"hunt"'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash ".../droplet-test.sh" AiInsightsWriterTest`
Expected: FAIL — a leads shop currently hits the bookings-only gate → `low_data`, no `hunt` block.

- [ ] **Step 3: Make the writer product-aware**

In `app/Services/Reports/AiInsightsWriter.php`:

(a) Add the model import and a new constant:

```php
use App\Models\Shop;
```
```php
    private const MIN_BOOKINGS = 5;
    private const MIN_HUNT_ACTIONS = 5;
```

(b) Replace the body of `summary()` from the line `$insights = $this->aggregator->insightsSummary(...)` down to (but NOT including) the `try {` block with:

```php
        $shop = Shop::find($shopId);
        $hasBookings = $shop !== null && ((bool) $shop->is_master || $shop->hasModule('bookings'));
        $hasLeads    = $shop !== null && ((bool) $shop->is_master || $shop->hasModule('leads'));

        $insights = $hasBookings ? $this->aggregator->insightsSummary($shopId, $from, $to) : null;
        $hunt     = $hasLeads ? $this->aggregator->huntSummary($shopId, $from, $to) : null;

        $bookingsQualifies = $insights !== null
            && (int) ($insights['bookings']['scheduled'] ?? 0) >= self::MIN_BOOKINGS;
        $huntActions = $hunt !== null ? ((int) $hunt['new_leads'] + array_sum($hunt['moved'])) : 0;
        $huntQualifies = $hunt !== null && $huntActions >= self::MIN_HUNT_ACTIONS;

        if (! $bookingsQualifies && ! $huntQualifies) {
            // Product-appropriate low-data message.
            $message = (! $hasBookings && $hasLeads)
                ? 'Not enough Business Hunt activity in this period yet to generate an AI summary. Check back once you have a few more leads.'
                : 'Not enough bookings in this period yet to generate an AI summary. Check back once you have a few more.';

            return $this->state('low_data', $message);
        }
```

(c) In the `try {` block, change the `buildPayload(...)` call to pass the qualified sections:

```php
            $recent = $this->recentSummaries($shopId);
            $payload = $this->buildPayload($shopId, $from, $to, [
                'bookings' => $bookingsQualifies ? $insights : null,
                'hunt'     => $huntQualifies ? $hunt : null,
            ], $recent);
```

(d) Replace the entire `buildPayload()` method with:

```php
    protected function buildPayload(int $shopId, Carbon $from, Carbon $to, array $qualified, array $recentSummaries = []): array
    {
        $lengthDays = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo   = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($lengthDays - 1)->startOfDay();

        $payload = [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $lengthDays],
            // Earlier summaries you wrote — vary your framing from these.
            'recent_summaries' => $recentSummaries,
        ];

        if (($insights = $qualified['bookings'] ?? null) !== null) {
            $revenue      = $this->aggregator->revenueSummary($shopId, $from, $to);
            $prevRevenue  = $this->aggregator->revenueSummary($shopId, $prevFrom, $prevTo);
            $prevInsights = $this->aggregator->insightsSummary($shopId, $prevFrom, $prevTo);

            $payload['bookings'] = [
                'current' => [
                    'bookings'          => $insights['bookings'],
                    'rates'             => $insights['rates'],
                    'customers'         => $insights['customers'],
                    'reviews'           => $insights['reviews'],
                    'gross_revenue'     => $revenue['kpis']['gross_revenue'],
                    'avg_booking_value' => $revenue['kpis']['avg_booking_value'],
                    'top_services'      => $revenue['top_services'],
                ],
                'previous' => [
                    'bookings'          => $prevInsights['bookings'],
                    'rates'             => $prevInsights['rates'],
                    'customers'         => $prevInsights['customers'],
                    'reviews'           => $prevInsights['reviews'],
                    'gross_revenue'     => $prevRevenue['kpis']['gross_revenue'],
                    'avg_booking_value' => $prevRevenue['kpis']['avg_booking_value'],
                ],
            ];
        }

        if (($hunt = $qualified['hunt'] ?? null) !== null) {
            $prevHunt = $this->aggregator->huntSummary($shopId, $prevFrom, $prevTo);

            $payload['hunt'] = [
                'current' => [
                    'new_leads'    => $hunt['new_leads'],
                    'pipeline'     => $hunt['pipeline'],
                    'total_leads'  => $hunt['total_leads'],
                    'moved'        => $hunt['moved'],
                    'won'          => $hunt['won'],
                    'credits_used' => $hunt['credits_used'],
                    'searches'     => $hunt['searches'],
                ],
                // pipeline/total_leads are a current snapshot — omit from previous.
                'previous' => [
                    'new_leads'    => $prevHunt['new_leads'],
                    'moved'        => $prevHunt['moved'],
                    'won'          => $prevHunt['won'],
                    'credits_used' => $prevHunt['credits_used'],
                    'searches'     => $prevHunt['searches'],
                ],
            ];
        }

        return $payload;
    }
```

(e) Replace the `systemPrompt()` body with the product-aware version:

```php
    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a plain-language business analyst for a small business. The business may take bookings (a service shop — salon, clinic, laundry) and/or run a "Business Hunt" outbound pipeline (finding and pursuing other businesses as leads).

You will receive a JSON object of computed metrics for the selected period and the previous equal-length period. It contains a "bookings" section and/or a "hunt" section — ONLY the sections the business actually uses are present.

Write a short, encouraging but honest performance summary for the owner, who is NOT technical.

STRICT RULES:
- Summarize ONLY the sections present in the JSON. If "bookings" is absent, say nothing about bookings or revenue; if "hunt" is absent, say nothing about leads.
- Use ONLY the numbers provided. Never invent figures, names, or trends the data does not show. Every statement must be supported by the actual numbers.
- Compare "current" vs "previous" to describe direction (up / down / flat). If a previous value is zero, describe it as a new or first-of-period result rather than citing a percentage change.
- In "hunt": "new_leads" = leads added this period; "pipeline" = the CURRENT count in each funnel stage (new, sent, replied, demo, won, pass); "moved" = how many leads advanced INTO each stage this period; "won" = leads won; "credits_used"/"searches" = search activity.
- No jargon. Refer to money as AED.
- Keep it concise.

Return ONLY a JSON object, no markdown fences, with exactly these keys:
{
  "summary": "2-3 sentence plain-language overview",
  "patterns": ["2-3 short notable patterns"],
  "recommendations": ["1-2 short concrete recommendations"]
}
PROMPT;
    }
```

- [ ] **Step 4: Run the full writer suite**

Run: `bash ".../droplet-test.sh" AiInsightsWriterTest`
Expected: PASS — the 3 new leads/mixed cases AND all pre-existing bookings cases (they use default-`bookings` shops with no leads, so behaviour is unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/AiInsightsWriter.php tests/Feature/AiInsightsWriterTest.php
git commit -m "feat(reports): product-aware AI summary (bookings and/or Hunt)"
```

---

### Task 3: Nightly job includes Hunt shops

`activeShopIds` becomes the union of booking shops and Hunt-active shops.

**Files:**
- Modify: `app/Console/Commands/GenerateDailyAiSummaries.php`
- Test: `tests/Feature/GenerateDailyAiSummariesTest.php` (extend)

**Interfaces:**
- Consumes: nothing new; reuses the writer (Task 2).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/GenerateDailyAiSummariesTest.php` (it already imports `AiSummary`, `Shop`, `Http`, `\DB`; add `use App\Models\Lead;` at the top):

```php
    private function leadsShop(string $code): Shop
    {
        return Shop::create(['name' => 'Hunt ' . $code, 'shop_code' => $code, 'pin' => '0000', 'category_id' => 11, 'modules' => ['leads']]);
    }

    /** Seed $count leads + a status_change each, dated inside the window (2 days ago). */
    private function seedHuntActivity(Shop $shop, int $count, int $daysAgo = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            $lead = Lead::create(['shop_id' => $shop->id, 'name' => "L{$i}", 'status' => 'sent']);
            $lead->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'new', 'to' => 'sent'], 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
            $lead->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
        }
    }

    public function test_generates_for_active_leads_shop_with_hunt_activity(): void
    {
        $shop = $this->leadsShop('9101');
        $this->seedHuntActivity($shop, 6); // 6 new + 6 moves >= 5 actions
        $this->fakeClaude();

        $this->artisan('ai:daily-summaries')->assertSuccessful();

        $this->assertSame(1, AiSummary::where('shop_id', $shop->id)->count());
    }

    public function test_ignores_leads_shop_with_no_window_activity(): void
    {
        $shop = $this->leadsShop('9102');
        $this->seedHuntActivity($shop, 6, daysAgo: 400); // outside the 30-day window
        Http::fake();

        $this->artisan('ai:daily-summaries')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, AiSummary::where('shop_id', $shop->id)->count());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash ".../droplet-test.sh" GenerateDailyAiSummariesTest`
Expected: FAIL on `test_generates_for_active_leads_shop_with_hunt_activity` — a leads-only shop is not in `activeShopIds` (bookings-only query), so nothing is generated.

- [ ] **Step 3: Union the active-shop query**

In `app/Console/Commands/GenerateDailyAiSummaries.php`, replace `activeShopIds()` with:

```php
    /**
     * Active shops = status 'active' with, in the window, either a booking OR
     * Business Hunt activity (a lead created, or a lead status change). Keeps the
     * nightly run off dormant tenants while covering both products.
     *
     * @return array<int, int>
     */
    protected function activeShopIds(string $fromDate): array
    {
        $bookingShops = DB::table('bookings')
            ->join('shops', 'shops.id', '=', 'bookings.shop_id')
            ->where('shops.status', 'active')
            ->where('bookings.date', '>=', $fromDate)
            ->distinct()->pluck('bookings.shop_id');

        $newLeadShops = DB::table('leads')
            ->join('shops', 'shops.id', '=', 'leads.shop_id')
            ->where('shops.status', 'active')
            ->where('leads.created_at', '>=', $fromDate)
            ->distinct()->pluck('leads.shop_id');

        $activityShops = DB::table('lead_activities')
            ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->join('shops', 'shops.id', '=', 'leads.shop_id')
            ->where('shops.status', 'active')
            ->where('lead_activities.type', 'status_change')
            ->where('lead_activities.created_at', '>=', $fromDate)
            ->distinct()->pluck('leads.shop_id');

        return $bookingShops->merge($newLeadShops)->merge($activityShops)
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
```

Also update the class docblock line "Only shops with bookings in the window are considered" to note it now also covers shops with Business Hunt activity.

- [ ] **Step 4: Run the full nightly-job suite**

Run: `bash ".../droplet-test.sh" GenerateDailyAiSummariesTest`
Expected: PASS — the 2 new leads cases AND the pre-existing booking cases (the union only ADDS leads shops; the existing shops have no leads, so their assertions are unchanged).

- [ ] **Step 5: Regression sweep + commit**

Run: `bash ".../droplet-test.sh" AiInsights` and `bash ".../droplet-test.sh" Reports`
Expected: PASS across `AiInsightsWriterTest`, `AiInsightsEndpointTest`, `ReportsAggregatorHuntTest`, and any other `*Reports*`/`*AiInsights*` suites.

```bash
git add app/Console/Commands/GenerateDailyAiSummaries.php tests/Feature/GenerateDailyAiSummariesTest.php
git commit -m "feat(reports): nightly AI summaries cover Hunt-active shops too"
```

---

## Deployment (out of scope for these tasks)

Backend-only. After all tasks are green on the droplet, promote per the standing flow (stage → verify → prod) when Francis triggers it. The nightly command already runs on prod/staging schedulers; no cron change needed.

## Self-Review

- **Spec coverage:** huntSummary metrics → Task 1; product-aware gate/payload/prompt → Task 2; nightly union → Task 3; no schema/frontend change (correct — none in the plan). Previous-hunt-block omits pipeline/total_leads per the spec note → implemented in Task 2 buildPayload.
- **Cross-DB:** `moved` aggregation decodes payload in PHP (no JSON SQL) — safe on sqlite tests + pgsql prod.
- **Regression safety:** existing writer/nightly tests use default-`bookings` shops with no leads; the new gate keeps their path identical (bookings message on low_data; bookings block on ok).
- **Type consistency:** `huntSummary(int,Carbon,Carbon): array` with keys used verbatim in `AiInsightsWriter::buildPayload`; `MIN_HUNT_ACTIONS` defined and used; `buildPayload` 4th arg changed from `$insights` to `$qualified` array in both the call site and the definition.
- **Placeholder scan:** none — full code and exact commands throughout.
