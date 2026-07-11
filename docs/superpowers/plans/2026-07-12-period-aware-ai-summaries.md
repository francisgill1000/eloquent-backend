# Period-aware AI Summaries + History — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the owner pick a period (30-day / weekly / monthly / custom) and get an AI summary generated fresh from that period's metrics, and browse past weekly/monthly summaries.

**Architecture:** `ai_summaries` gains a `period_type`; `AiInsightsWriter::summary()` gains a `periodType` arg and persists/retrieves by `(shop_id, period_type, period_from, period_to)`. New history endpoint + a `--period=week|month` scheduled command (sharing a trait with the daily one). Frontend adds a period selector + history list + custom range, reusing the existing summary card.

**Tech Stack:** Laravel (PHP 8.4), Eloquent, PHPUnit (backend, on the droplet). React + TypeScript + Vitest (frontend, local).

## Global Constraints

- **Backend tests run on the droplet only** (sqlite `:memory:` harness) — never local, never prod. Harness: `bash "C:/Users/franc/AppData/Local/Temp/claude/d--Francis-projects-2026-Eloquent-Solutions-Business-Lens/60d12e62-82b5-4d57-a7a4-5a66113a9f3c/scratchpad/droplet-test.sh" <Filter>`.
- **Frontend tests run locally** in `admin/`: `npx tsc --noEmit` (types) and `npx vitest run <file>` (unit). Do NOT use the droplet for frontend.
- **Cross-DB:** migration must run on sqlite (tests) and Postgres (prod) — `dropUnique`/`unique` only (DROP/CREATE INDEX), no raw ALTER.
- **Preserve existing behaviour:** the rolling-30 daily summary, its nightly job, and the current `/ai-summary` default view must be unchanged for users. Existing `AiInsightsWriterTest` / `GenerateDailyAiSummariesTest` stay green.
- **Multi-tenant:** every query scoped by `shop_id`. Follow the existing `/shop/reports/*` param pattern (`shop_id` in the request) — do NOT change the auth posture here.
- `period_type` ∈ `rolling30 | week | month | custom`. Work on `main`; commit per task; do NOT deploy.

---

### Task 1: `period_type` on `ai_summaries`

**Files:**
- Create: `database/migrations/2026_07_12_000001_add_period_type_to_ai_summaries.php`
- Modify: `app/Models/AiSummary.php`
- Test: `tests/Feature/AiSummaryPeriodTypeTest.php` (create)

**Interfaces:**
- Produces: `ai_summaries.period_type` (string, default `rolling30`); unique key `(shop_id, period_type, period_from, period_to)`. `AiSummary` `$fillable` includes `period_type`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AiSummaryPeriodTypeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AiSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSummaryPeriodTypeTest extends TestCase
{
    use RefreshDatabase;

    private function row(array $overrides = []): AiSummary
    {
        return AiSummary::create(array_merge([
            'shop_id' => 1, 'summary_date' => '2026-07-12',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-07',
            'summary' => 's', 'patterns' => ['p'], 'recommendations' => ['r'],
            'period_type' => 'week',
        ], $overrides));
    }

    public function test_period_type_defaults_to_rolling30(): void
    {
        $r = AiSummary::create([
            'shop_id' => 1, 'summary_date' => '2026-07-12',
            'period_from' => '2026-06-13', 'period_to' => '2026-07-12',
            'summary' => 's', 'patterns' => [], 'recommendations' => [],
        ]);
        $this->assertSame('rolling30', $r->fresh()->period_type);
    }

    public function test_unique_is_scoped_by_period_type_and_window(): void
    {
        $this->row(); // week 07-01..07-07

        // Same shop + window but a DIFFERENT period_type is allowed.
        $this->row(['period_type' => 'custom']);
        $this->assertSame(2, AiSummary::count());

        // Same shop + period_type + window collides (unique) — updateOrCreate upserts.
        AiSummary::updateOrCreate(
            ['shop_id' => 1, 'period_type' => 'week', 'period_from' => '2026-07-01', 'period_to' => '2026-07-07'],
            ['summary_date' => '2026-07-13', 'summary' => 'updated', 'patterns' => [], 'recommendations' => []],
        );
        $this->assertSame(2, AiSummary::count());
        $this->assertSame('updated', AiSummary::where('period_type', 'week')->first()->summary);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash ".../droplet-test.sh" AiSummaryPeriodTypeTest`
Expected: FAIL — `period_type` column does not exist.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_12_000001_add_period_type_to_ai_summaries.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a period_type to ai_summaries so a shop can hold rolling30/week/month/
 * custom summaries side by side, and re-keys uniqueness on
 * (shop_id, period_type, period_from, period_to). Existing rows backfill to
 * 'rolling30' (the only kind that existed before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_summaries', function (Blueprint $table) {
            $table->string('period_type')->default('rolling30')->after('shop_id');
        });

        Schema::table('ai_summaries', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'summary_date']);
            $table->unique(['shop_id', 'period_type', 'period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_summaries', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'period_type', 'period_from', 'period_to']);
            $table->unique(['shop_id', 'summary_date']);
        });

        Schema::table('ai_summaries', function (Blueprint $table) {
            $table->dropColumn('period_type');
        });
    }
};
```

- [ ] **Step 4: Add `period_type` to the model fillable**

In `app/Models/AiSummary.php`, change the `$fillable` array to include `period_type`:

```php
    protected $fillable = [
        'shop_id', 'period_type', 'summary_date', 'period_from', 'period_to',
        'summary', 'patterns', 'recommendations', 'model',
    ];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `bash ".../droplet-test.sh" AiSummaryPeriodTypeTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_12_000001_add_period_type_to_ai_summaries.php app/Models/AiSummary.php tests/Feature/AiSummaryPeriodTypeTest.php
git commit -m "feat(reports): add period_type to ai_summaries"
```

---

### Task 2: Period-aware `AiInsightsWriter`

**Files:**
- Modify: `app/Services/Reports/AiInsightsWriter.php`
- Test: `tests/Feature/AiInsightsWriterTest.php` (extend)

**Interfaces:**
- Produces: `summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh = false, string $periodType = 'custom'): array` — persists/retrieves by `(shop_id, period_type, period_from, period_to)`; `rolling30` keeps the `latestStored` fallback.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AiInsightsWriterTest.php`:

```php
    public function test_week_summary_persists_a_week_row_and_is_served_from_store(): void
    {
        $shop = $this->shop('7201');
        $this->seedBookings($shop, 6);
        $this->fakeClaude(['summary' => 'Week view.', 'patterns' => ['a'], 'recommendations' => ['b']]);

        $from = now()->startOfWeek(); $to = now()->endOfWeek();
        $first = $this->writer()->summary($shop->id, $from, $to, false, 'week');
        $this->assertSame('ok', $first['state']);
        $this->assertSame('week', \App\Models\AiSummary::where('shop_id', $shop->id)->first()->period_type);

        // A repeat (cache flushed) is served from the stored week row — no 2nd call.
        \Illuminate\Support\Facades\Cache::flush();
        $second = $this->writer()->summary($shop->id, $from, $to, false, 'week');
        $this->assertSame('ok', $second['state']);
        $this->assertTrue($second['cached']);
        Http::assertSentCount(1);
    }

    public function test_period_type_scopes_the_cache_key(): void
    {
        $shop = $this->shop('7202');
        $this->seedBookings($shop, 6);
        $this->fakeClaude(['summary' => 'x', 'patterns' => ['a'], 'recommendations' => ['b']]);

        $from = now()->startOfMonth(); $to = now()->endOfMonth();
        // Same window, different period_type → two separate generations (no cache collision).
        $this->writer()->summary($shop->id, $from, $to, false, 'month');
        $this->writer()->summary($shop->id, $from, $to, false, 'custom');
        Http::assertSentCount(2);
        $this->assertSame(2, \App\Models\AiSummary::where('shop_id', $shop->id)->count());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash ".../droplet-test.sh" AiInsightsWriterTest`
Expected: FAIL — `summary()` has no `$periodType`; persistence/cache aren't type-scoped.

- [ ] **Step 3: Make the writer period-aware**

In `app/Services/Reports/AiInsightsWriter.php`:

(a) Change the `summary()` signature and the cache key + retrieval. Replace the method header and the cache/stored block (lines from `public function summary(...)` through the `latestStored` fallback) with:

```php
    public function summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh = false, string $periodType = 'custom'): array
    {
        $key = sprintf('ai_insights:%s:%d:%s:%s', $periodType, $shopId, $from->toDateString(), $to->toDateString());

        if (! $forceRefresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return array_merge($cached, ['cached' => true]);
            }

            // Exact stored row for this (type, window). For rolling30 only, fall
            // back to the latest stored rolling30 row so the morning load stays
            // instant and tolerant of a ±1 day timezone boundary (as before).
            $stored = $this->storedFor($shopId, $periodType, $from, $to);
            if ($stored === null && $periodType === 'rolling30') {
                $stored = $this->latestStored($shopId, 'rolling30');
            }
            if ($stored !== null) {
                return $this->fromStored($stored);
            }
        }
```

(b) Change the generate-success persistence call. Find `$this->persistDaily($shopId, $from, $to, $parsed);` and replace with:

```php
        $this->persist($shopId, $from, $to, $parsed, $periodType);
```

(c) Replace `recentSummaries()`, `latestStored()`, and `persistDaily()` with type-aware versions, and add `storedFor()`:

```php
    /**
     * The shop's most recent rolling30 summaries — fed to the model so a new
     * day's summary reads differently from earlier ones.
     *
     * @return array<int, string>
     */
    protected function recentSummaries(int $shopId): array
    {
        return AiSummary::where('shop_id', $shopId)
            ->where('period_type', 'rolling30')
            ->orderByDesc('summary_date')
            ->limit(3)
            ->pluck('summary')
            ->all();
    }

    /** Exact stored summary for one (shop, period_type, window), or null. */
    protected function storedFor(int $shopId, string $periodType, Carbon $from, Carbon $to): ?AiSummary
    {
        return AiSummary::where('shop_id', $shopId)
            ->where('period_type', $periodType)
            ->whereDate('period_from', $from->toDateString())
            ->whereDate('period_to', $to->toDateString())
            ->first();
    }

    /** The shop's most recent stored summary of a given type, or null. */
    protected function latestStored(int $shopId, string $periodType = 'rolling30'): ?AiSummary
    {
        return AiSummary::where('shop_id', $shopId)
            ->where('period_type', $periodType)
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Upsert one row per (shop, period_type, window). Failure must never break
     * the reply.
     *
     * @param array{summary: string, patterns: string[], recommendations: string[]} $parsed
     */
    protected function persist(int $shopId, Carbon $from, Carbon $to, array $parsed, string $periodType): void
    {
        try {
            AiSummary::updateOrCreate(
                [
                    'shop_id'     => $shopId,
                    'period_type' => $periodType,
                    'period_from' => $from->toDateString(),
                    'period_to'   => $to->toDateString(),
                ],
                [
                    'summary_date'    => Carbon::now()->toDateString(),
                    'summary'         => $parsed['summary'],
                    'patterns'        => $parsed['patterns'],
                    'recommendations' => $parsed['recommendations'],
                    'model'           => (string) config('services.anthropic.model'),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('AiInsightsWriter persist failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
        }
    }
```

Leave `fromStored()`, `buildPayload()`, `parse()`, `state()`, `systemPrompt()`, and the product-aware gate unchanged.

- [ ] **Step 4: Run the full writer suite**

Run: `bash ".../droplet-test.sh" AiInsightsWriterTest`
Expected: PASS — the 2 new period cases AND all pre-existing cases (they default to `periodType='custom'`; `test_serves_stored_summary_from_db_without_calling_claude` still works because the two calls share the same window → exact `storedFor` match).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/AiInsightsWriter.php tests/Feature/AiInsightsWriterTest.php
git commit -m "feat(reports): period-aware writer (persist/retrieve by period_type)"
```

---

### Task 3: Endpoints + frontend lib

**Files:**
- Modify: `app/Http/Controllers/ReportsController.php`
- Modify: `routes/api.php`
- Modify: `admin/src/lib/aiInsights.ts`
- Test: `tests/Feature/AiSummaryHistoryTest.php` (create)

**Interfaces:**
- Produces: `GET /shop/reports/ai-summary?...&period=` (validated) → writer with `$period`. `GET /shop/reports/ai-summaries?shop_id=&period_type=&limit=&page=` → `{ data: [{period_from, period_to, summary, patterns, recommendations, generated_at}], has_more }`. Frontend `getAiInsights(shopId, from, to, refresh, period)` and `getAiSummaryHistory(shopId, periodType, page)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AiSummaryHistoryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AiSummary;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSummaryHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'S' . $code, 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    private function seedWeek(int $shopId, string $from, string $to): void
    {
        AiSummary::create([
            'shop_id' => $shopId, 'period_type' => 'week', 'summary_date' => $to,
            'period_from' => $from, 'period_to' => $to,
            'summary' => "Week {$from}", 'patterns' => ['p'], 'recommendations' => ['r'],
        ]);
    }

    public function test_history_returns_only_the_requested_type_newest_first(): void
    {
        $shop = $this->shop('7301');
        $this->seedWeek($shop->id, '2026-06-01', '2026-06-07');
        $this->seedWeek($shop->id, '2026-06-08', '2026-06-14');
        // A month row must NOT appear in a week query.
        AiSummary::create(['shop_id' => $shop->id, 'period_type' => 'month', 'summary_date' => '2026-06-30',
            'period_from' => '2026-06-01', 'period_to' => '2026-06-30', 'summary' => 'M', 'patterns' => [], 'recommendations' => []]);

        $res = $this->getJson("/api/shop/reports/ai-summaries?shop_id={$shop->id}&period_type=week");

        $res->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.period_from', '2026-06-08') // newest first
            ->assertJsonPath('has_more', false);
    }

    public function test_history_is_tenant_scoped(): void
    {
        $a = $this->shop('7302');
        $b = $this->shop('7303');
        $this->seedWeek($b->id, '2026-06-01', '2026-06-07');

        $res = $this->getJson("/api/shop/reports/ai-summaries?shop_id={$a->id}&period_type=week");
        $res->assertOk()->assertJsonCount(0, 'data');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash ".../droplet-test.sh" AiSummaryHistoryTest`
Expected: FAIL — the `ai-summaries` route/method does not exist.

- [ ] **Step 3: Extend `aiSummary` + add `aiSummaryHistory`**

In `app/Http/Controllers/ReportsController.php`, replace `aiSummary()` and add `aiSummaryHistory()`:

```php
    public function aiSummary(Request $request, AiInsightsWriter $writer)
    {
        $request->validate(['period' => 'sometimes|in:rolling30,week,month,custom']);
        [$shopId, $from, $to] = $this->validated($request);
        $period = $request->input('period', 'custom');

        return response()->json($writer->summary($shopId, $from, $to, $request->boolean('refresh'), $period));
    }

    public function aiSummaryHistory(Request $request)
    {
        $request->validate([
            'shop_id'     => 'required|exists:shops,id',
            'period_type' => 'required|in:rolling30,week,month,custom',
            'limit'       => 'sometimes|integer|min:1|max:60',
            'page'        => 'sometimes|integer|min:1',
        ]);

        $limit = (int) $request->input('limit', 12);
        $page  = (int) $request->input('page', 1);

        $rows = \App\Models\AiSummary::where('shop_id', (int) $request->shop_id)
            ->where('period_type', $request->period_type)
            ->orderByDesc('period_from')->orderByDesc('id')
            ->offset(($page - 1) * $limit)->limit($limit + 1)
            ->get(['period_from', 'period_to', 'summary', 'patterns', 'recommendations', 'updated_at']);

        $hasMore = $rows->count() > $limit;

        return response()->json([
            'data' => $rows->take($limit)->map(fn ($r) => [
                'period_from'     => $r->period_from->toDateString(),
                'period_to'       => $r->period_to->toDateString(),
                'summary'         => $r->summary,
                'patterns'        => $r->patterns,
                'recommendations' => $r->recommendations,
                'generated_at'    => optional($r->updated_at)->toIso8601String(),
            ])->values(),
            'has_more' => $hasMore,
        ]);
    }
```

- [ ] **Step 4: Register the history route**

In `routes/api.php`, right after the existing `ai-summary` line (99), add:

```php
Route::get('/shop/reports/ai-summaries',   [\App\Http\Controllers\ReportsController::class, 'aiSummaryHistory']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `bash ".../droplet-test.sh" AiSummaryHistoryTest`
Expected: PASS (2 tests). If the routes are cached on the harness, the harness's `config:clear` handles it; these tests hit the route directly.

- [ ] **Step 6: Extend the frontend lib**

Replace `admin/src/lib/aiInsights.ts` with:

```ts
import api from './api';

export type PeriodType = 'rolling30' | 'week' | 'month' | 'custom';

export type AiInsights = {
  state: 'ok' | 'low_data' | 'error';
  summary: string;
  patterns: string[];
  recommendations: string[];
  message: string;
  generated_at: string;
  cached: boolean;
};

export type AiSummaryHistoryItem = {
  period_from: string;
  period_to: string;
  summary: string;
  patterns: string[];
  recommendations: string[];
  generated_at: string;
};

export async function getAiInsights(
  shopId: number,
  from: string,
  to: string,
  refresh = false,
  period: PeriodType = 'rolling30',
): Promise<AiInsights> {
  const { data } = await api.get('/shop/reports/ai-summary', {
    params: { shop_id: shopId, from, to, period, ...(refresh ? { refresh: 1 } : {}) },
  });
  return data;
}

export async function getAiSummaryHistory(
  shopId: number,
  periodType: Exclude<PeriodType, 'custom'>,
  page = 1,
): Promise<{ data: AiSummaryHistoryItem[]; has_more: boolean }> {
  const { data } = await api.get('/shop/reports/ai-summaries', {
    params: { shop_id: shopId, period_type: periodType, page },
  });
  return data;
}
```

- [ ] **Step 7: Typecheck the frontend + commit**

Run (in `admin/`): `npx tsc --noEmit`
Expected: no errors.

```bash
git add app/Http/Controllers/ReportsController.php routes/api.php admin/src/lib/aiInsights.ts tests/Feature/AiSummaryHistoryTest.php
git commit -m "feat(reports): period param on ai-summary + ai-summaries history endpoint"
```

---

### Task 4: Scheduled weekly/monthly generation

**Files:**
- Create: `app/Console/Commands/Concerns/GeneratesShopSummaries.php`
- Create: `app/Console/Commands/GeneratePeriodAiSummaries.php`
- Modify: `app/Console/Commands/GenerateDailyAiSummaries.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/GeneratePeriodAiSummariesTest.php` (create)

**Interfaces:**
- Consumes: `AiInsightsWriter::summary(..., $periodType)` (Task 2).
- Produces: `ai:period-summaries {--period=week|month}`; shared trait `GeneratesShopSummaries::activeShopIds()` + `runFor()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/GeneratePeriodAiSummariesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AiSummary;
use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeneratePeriodAiSummariesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function fakeClaude(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['summary' => 'Period.', 'patterns' => ['p'], 'recommendations' => ['r']])]],
        ], 200)]);
    }

    private function bookingsShop(string $code): Shop
    {
        return Shop::create(['name' => 'S' . $code, 'shop_code' => $code, 'pin' => '0', 'category_id' => 11]);
    }

    /** Seed $count bookings inside last week (5 days ago). */
    private function seedLastWeekBookings(Shop $shop, int $count): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'shop_id' => $shop->id, 'date' => now()->subDays(5)->toDateString(),
                'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'completed',
                'charges' => 50, 'discount_amount' => 0,
                'services' => json_encode([['id' => 1, 'title' => 'Haircut', 'price' => '50.00']]),
                'booking_reference' => 'BK' . $shop->id . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_name' => 'C' . $i, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        \DB::table('bookings')->insert($rows);
    }

    public function test_weekly_generates_a_week_row_for_active_shop(): void
    {
        $shop = $this->bookingsShop('9201');
        $this->seedLastWeekBookings($shop, 6);
        $this->fakeClaude();

        $this->artisan('ai:period-summaries', ['--period' => 'week'])->assertSuccessful();

        $row = AiSummary::where('shop_id', $shop->id)->where('period_type', 'week')->first();
        $this->assertNotNull($row);
        $this->assertSame(now()->subWeek()->startOfWeek()->toDateString(), $row->period_from->toDateString());
    }

    public function test_skips_shop_with_no_activity_in_the_week_window(): void
    {
        $shop = $this->bookingsShop('9202'); // no bookings at all
        Http::fake();

        $this->artisan('ai:period-summaries', ['--period' => 'week'])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, AiSummary::where('shop_id', $shop->id)->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash ".../droplet-test.sh" GeneratePeriodAiSummariesTest`
Expected: FAIL — the `ai:period-summaries` command does not exist.

- [ ] **Step 3: Extract the shared trait**

Create `app/Console/Commands/Concerns/GeneratesShopSummaries.php` (move the union query verbatim out of the daily command, add the iteration helper):

```php
<?php

namespace App\Console\Commands\Concerns;

use App\Services\Reports\AiInsightsWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait GeneratesShopSummaries
{
    /**
     * Active shops = status 'active' with, in the window, either a booking OR
     * Business Hunt activity (a lead created, or a lead status change).
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

    /**
     * Generate + persist a summary for each shop over [from,to] as $periodType.
     *
     * @param  array<int,int>  $shopIds
     * @return array{ok:int, skipped:int, failed:int}
     */
    protected function runFor(AiInsightsWriter $writer, array $shopIds, Carbon $from, Carbon $to, string $periodType): array
    {
        $ok = $skipped = $failed = 0;
        foreach ($shopIds as $shopId) {
            try {
                $result = $writer->summary($shopId, $from->copy(), $to->copy(), true, $periodType);
                $result['state'] === 'ok' ? $ok++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('summary generation failed', ['shop_id' => $shopId, 'period' => $periodType, 'error' => $e->getMessage()]);
            }
        }

        return ['ok' => $ok, 'skipped' => $skipped, 'failed' => $failed];
    }
}
```

- [ ] **Step 4: Refactor the daily command onto the trait**

Replace `app/Console/Commands/GenerateDailyAiSummaries.php` with:

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GeneratesShopSummaries;
use App\Services\Reports\AiInsightsWriter;
use Illuminate\Console\Command;

/**
 * Pre-generates each active shop's rolling-30-day AI summary overnight (window =
 * 30 days ending yesterday) so the morning load is instant. Covers shops with
 * bookings OR Business Hunt activity in the window; the writer's per-product gate
 * skips still-too-quiet shops. Tenant-scoped; one failure never stops the rest.
 */
class GenerateDailyAiSummaries extends Command
{
    use GeneratesShopSummaries;

    protected $signature = 'ai:daily-summaries {--shop= : Limit to a single shop id (for testing)}';

    protected $description = 'Pre-generate active shops\' rolling-30-day AI summaries for the 30 days ending yesterday';

    public function handle(AiInsightsWriter $writer): int
    {
        $to   = now()->subDay()->endOfDay();
        $from = $to->copy()->subDays(29)->startOfDay();

        $shopIds = $this->option('shop')
            ? [(int) $this->option('shop')]
            : $this->activeShopIds($from->toDateString());

        $r = $this->runFor($writer, $shopIds, $from, $to, 'rolling30');

        $this->info("AI daily summaries: {$r['ok']} generated, {$r['skipped']} skipped (low data), {$r['failed']} failed.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Create the period command**

Create `app/Console/Commands/GeneratePeriodAiSummaries.php`:

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GeneratesShopSummaries;
use App\Services\Reports\AiInsightsWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pre-generates weekly (last complete Mon–Sun) or monthly (last complete calendar
 * month) AI summaries for active shops, so those history views load instantly.
 */
class GeneratePeriodAiSummaries extends Command
{
    use GeneratesShopSummaries;

    protected $signature = 'ai:period-summaries {--period=week : week|month} {--shop= : Limit to a single shop id}';

    protected $description = 'Pre-generate active shops\' weekly or monthly AI summaries for the last complete period';

    public function handle(AiInsightsWriter $writer): int
    {
        $period = $this->option('period');
        if (! in_array($period, ['week', 'month'], true)) {
            $this->error("Unknown --period '{$period}' (use week or month).");

            return self::INVALID;
        }

        if ($period === 'week') {
            $from = now()->subWeek()->startOfWeek(Carbon::MONDAY);
            $to   = now()->subWeek()->endOfWeek(Carbon::SUNDAY);
        } else {
            $from = now()->subMonthNoOverflow()->startOfMonth();
            $to   = now()->subMonthNoOverflow()->endOfMonth();
        }

        $shopIds = $this->option('shop')
            ? [(int) $this->option('shop')]
            : $this->activeShopIds($from->toDateString());

        $r = $this->runFor($writer, $shopIds, $from, $to, $period);

        $this->info("AI {$period} summaries: {$r['ok']} generated, {$r['skipped']} skipped (low data), {$r['failed']} failed.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Schedule the new commands**

In `routes/console.php`, after the existing `ai:daily-summaries` schedule block, add:

```php
Schedule::command('ai:period-summaries --period=week')
    ->weeklyOn(1, '03:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('ai:period-summaries --period=month')
    ->monthlyOn(1, '04:00')
    ->withoutOverlapping()
    ->onOneServer();
```

- [ ] **Step 7: Run the command + daily regression**

Run: `bash ".../droplet-test.sh" GeneratePeriodAiSummariesTest` then `bash ".../droplet-test.sh" GenerateDailyAiSummariesTest`
Expected: PASS both — the new week cases AND the pre-existing daily cases (the trait refactor is behaviour-preserving; the daily command still emits "N generated").

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/Concerns/GeneratesShopSummaries.php app/Console/Commands/GeneratePeriodAiSummaries.php app/Console/Commands/GenerateDailyAiSummaries.php routes/console.php tests/Feature/GeneratePeriodAiSummariesTest.php
git commit -m "feat(reports): scheduled weekly/monthly AI summaries via shared trait"
```

---

### Task 5: Frontend period selector + history + custom range

**Files:**
- Modify: `admin/src/pages/AiSummary.tsx`
- Modify: `admin/src/styles/insights.css`
- Test: `admin/src/pages/AiSummary.periods.test.tsx` (create)

**Interfaces:**
- Consumes: `getAiInsights(shopId, from, to, refresh, period)` and `getAiSummaryHistory(shopId, periodType, page)` (Task 3).

- [ ] **Step 1: Write the failing test**

Create `admin/src/pages/AiSummary.periods.test.tsx`:

```tsx
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import AiSummary from './AiSummary';

vi.mock('@/context/ShopContext', () => ({ useShop: () => ({ shop: { id: 1, name: 'Test' } }) }));
vi.mock('@/lib/simulation', () => ({ speak: vi.fn() }));

const getAiInsights = vi.fn();
const getAiSummaryHistory = vi.fn();
vi.mock('@/lib/aiInsights', () => ({
  getAiInsights: (...a: unknown[]) => getAiInsights(...a),
  getAiSummaryHistory: (...a: unknown[]) => getAiSummaryHistory(...a),
}));

const ok = {
  state: 'ok', summary: 'S', patterns: [], recommendations: [],
  message: '', generated_at: '', cached: false,
};

beforeEach(() => {
  getAiInsights.mockReset().mockResolvedValue(ok);
  getAiSummaryHistory.mockReset().mockResolvedValue({ data: [], has_more: false });
});

describe('AiSummary period selector', () => {
  it('defaults to the rolling30 period on first load', async () => {
    render(<AiSummary />);
    await waitFor(() => expect(getAiInsights).toHaveBeenCalled());
    const [, , , , period] = getAiInsights.mock.calls[0];
    expect(period).toBe('rolling30');
  });

  it('switches to weekly and refetches with period=week + loads week history', async () => {
    render(<AiSummary />);
    await waitFor(() => expect(getAiInsights).toHaveBeenCalled());

    fireEvent.click(screen.getByRole('button', { name: /weekly/i }));

    await waitFor(() => {
      const lastCall = getAiInsights.mock.calls.at(-1)!;
      expect(lastCall[4]).toBe('week');
    });
    expect(getAiSummaryHistory).toHaveBeenCalledWith(1, 'week', expect.anything());
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (in `admin/`): `npx vitest run src/pages/AiSummary.periods.test.tsx`
Expected: FAIL — no period selector; `getAiSummaryHistory` never called; first call's period arg is undefined/wrong.

- [ ] **Step 3: Rewrite the page with a period selector**

Replace `admin/src/pages/AiSummary.tsx` with:

```tsx
import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import {
  getAiInsights, getAiSummaryHistory,
  type AiInsights, type PeriodType, type AiSummaryHistoryItem,
} from '@/lib/aiInsights';
import { speak } from '@/lib/simulation';
import '@/styles/insights.css';

/* ---------- date helpers ---------------------------------------------------- */
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const startOfWeekMon = (d: Date) => { const x = new Date(d); const dow = (x.getDay() + 6) % 7; return addDays(x, -dow); };
const startOfMonth = (d: Date) => new Date(d.getFullYear(), d.getMonth(), 1);

/** The from/to window + human label for a given period selection (current period). */
function currentWindow(period: PeriodType): { from: string; to: string; label: string } {
  const today = new Date();
  if (period === 'week') {
    return { from: iso(startOfWeekMon(today)), to: iso(today), label: 'This week so far' };
  }
  if (period === 'month') {
    return { from: iso(startOfMonth(today)), to: iso(today), label: 'This month so far' };
  }
  // rolling30 (default): the 30 complete days ending yesterday.
  const yesterday = addDays(today, -1);
  return { from: iso(addDays(yesterday, -29)), to: iso(yesterday), label: 'Last 30 days' };
}

const fmt = (s: string) => new Date(s + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const historyLabel = (it: AiSummaryHistoryItem) => `${fmt(it.period_from)} – ${fmt(it.period_to)}`;

/* ---------- AI summary card ------------------------------------------------- */
function AiInsightsCard({ data, loading, refreshing, subtitle, onRefresh }: {
  data: AiInsights | null; loading: boolean; refreshing: boolean; subtitle: string; onRefresh: () => void;
}) {
  const [audio, setAudio] = useState<'idle' | 'loading' | 'playing'>('idle');
  const audioRef = useRef<HTMLAudioElement | null>(null);
  useEffect(() => () => { audioRef.current?.pause(); }, []);
  const canListen = !!data && data.state === 'ok';

  const onListen = async () => {
    if (audio === 'playing') { audioRef.current?.pause(); setAudio('idle'); return; }
    if (!data || data.state !== 'ok') return;
    const text = [data.summary, ...data.patterns, ...data.recommendations].filter(Boolean).join('. ').slice(0, 780);
    try {
      setAudio('loading');
      const url = await speak(text, 'nova');
      const el = new Audio(url);
      audioRef.current = el;
      el.onended = () => { setAudio('idle'); URL.revokeObjectURL(url); };
      el.onerror = () => setAudio('idle');
      await el.play();
      setAudio('playing');
    } catch { setAudio('idle'); }
  };

  return (
    <div className="ins-card span2 ins-ai">
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icons.Sparkle size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">AI summary</span>
          <span className="ins-card-sub">{subtitle}</span>
        </span>
        {canListen && (
          <div className="ins-ai-actions">
            <button className="ins-ai-listen" onClick={onListen} disabled={audio === 'loading'}
              aria-label={audio === 'playing' ? 'Stop' : 'Listen'}>
              {audio === 'playing' ? <Icons.Stop size={14} /> : <Icons.Speaker size={14} />}
              {audio === 'loading' ? 'Loading…' : audio === 'playing' ? 'Stop' : 'Listen'}
            </button>
          </div>
        )}
      </div>

      {loading ? (
        <div className="ins-ai-body">
          <div className="ins-skel" style={{ height: 16, marginBottom: 8 }} />
          <div className="ins-skel" style={{ height: 16, width: '80%', marginBottom: 16 }} />
          <div className="ins-skel" style={{ height: 48 }} />
        </div>
      ) : !data || data.state === 'error' ? (
        <div className="ins-ai-body">
          <p className="ins-ai-msg">{data?.message || 'Could not generate the AI summary right now.'}</p>
          <button className="ins-ai-retry" onClick={onRefresh}>Try again</button>
        </div>
      ) : data.state === 'low_data' ? (
        <div className="ins-ai-body"><p className="ins-ai-msg">{data.message}</p></div>
      ) : (
        <div className={`ins-ai-body${refreshing ? ' is-refreshing' : ''}`}>
          <p className="ins-ai-summary">{data.summary}</p>
          {data.patterns.length > 0 && (
            <div className="ins-ai-block">
              <span className="ins-ai-label">Patterns</span>
              <ul className="ins-ai-list">{data.patterns.map((p, i) => <li key={i}>{p}</li>)}</ul>
            </div>
          )}
          {data.recommendations.length > 0 && (
            <div className="ins-ai-block">
              <span className="ins-ai-label">Recommendations</span>
              <ul className="ins-ai-list">{data.recommendations.map((r, i) => <li key={i}>{r}</li>)}</ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/* ---------- page ----------------------------------------------------------- */
const TABS: { id: PeriodType; label: string }[] = [
  { id: 'rolling30', label: '30-day' },
  { id: 'week', label: 'Weekly' },
  { id: 'month', label: 'Monthly' },
  { id: 'custom', label: 'Custom' },
];

export default function AiSummary() {
  const { shop } = useShop();
  const [period, setPeriod] = useState<PeriodType>('rolling30');

  // The active window: the current period, a picked history row, or a custom range.
  const [win, setWin] = useState(() => currentWindow('rolling30'));
  const [history, setHistory] = useState<AiSummaryHistoryItem[]>([]);
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');

  const [data, setData] = useState<AiInsights | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchAi = useCallback(async (from: string, to: string, refresh = false) => {
    if (!shop?.id || !from || !to) return;
    refresh ? setRefreshing(true) : setLoading(true);
    try {
      setData(await getAiInsights(shop.id, from, to, refresh, period));
    } catch {
      setData({ state: 'error', summary: '', patterns: [], recommendations: [],
        message: 'Could not generate the AI summary right now.', generated_at: '', cached: false });
    } finally { setLoading(false); setRefreshing(false); }
  }, [shop?.id, period]);

  // On period change: reset to that period's current window + load its history.
  useEffect(() => {
    if (period === 'custom') { setData(null); setLoading(false); return; }
    const w = currentWindow(period);
    setWin(w);
    void fetchAi(w.from, w.to, false);
    if (period === 'week' || period === 'month') {
      getAiSummaryHistory(shop!.id, period, 1).then((r) => setHistory(r.data)).catch(() => setHistory([]));
    } else {
      setHistory([]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [period, shop?.id]);

  const pickHistory = (it: AiSummaryHistoryItem) => {
    setWin({ from: it.period_from, to: it.period_to, label: historyLabel(it) });
    // History rows are already stored — a normal (non-refresh) fetch serves them instantly.
    void fetchAi(it.period_from, it.period_to, false);
  };

  const runCustom = () => {
    if (!customFrom || !customTo) return;
    setWin({ from: customFrom, to: customTo, label: `${fmt(customFrom)} – ${fmt(customTo)}` });
    void fetchAi(customFrom, customTo, false);
  };

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">AI summary</h1>
        <p className="c-page-sub">A plain-language read on your business, written by AI.</p>
      </div>

      <div className="ins-tabs" role="tablist">
        {TABS.map((t) => (
          <button key={t.id} role="tab" aria-selected={period === t.id}
            className={`ins-tab${period === t.id ? ' is-active' : ''}`}
            onClick={() => setPeriod(t.id)}>{t.label}</button>
        ))}
      </div>

      {period === 'custom' && (
        <div className="ins-custom">
          <input type="date" aria-label="From" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} />
          <input type="date" aria-label="To" value={customTo} onChange={(e) => setCustomTo(e.target.value)} />
          <button className="ins-custom-go" onClick={runCustom} disabled={!customFrom || !customTo}>Generate</button>
        </div>
      )}

      {(period === 'week' || period === 'month') && history.length > 0 && (
        <div className="ins-history">
          <button className={`ins-hist-item${win.label.includes('so far') ? ' is-active' : ''}`}
            onClick={() => { const w = currentWindow(period); setWin(w); void fetchAi(w.from, w.to, false); }}>
            {period === 'week' ? 'This week' : 'This month'}
          </button>
          {history.map((it) => (
            <button key={`${it.period_from}_${it.period_to}`}
              className={`ins-hist-item${win.from === it.period_from && win.to === it.period_to ? ' is-active' : ''}`}
              onClick={() => pickHistory(it)}>{historyLabel(it)}</button>
          ))}
        </div>
      )}

      <div className="ins-wrap">
        <AiInsightsCard data={data} loading={loading} refreshing={refreshing}
          subtitle={win.label} onRefresh={() => fetchAi(win.from, win.to, true)} />
      </div>
    </div></div>
  );
}
```

- [ ] **Step 4: Add styles**

Append to `admin/src/styles/insights.css`:

```css
/* Period selector + history for the AI summary page */
.ins-tabs { display: flex; gap: 6px; margin: 0 0 12px; flex-wrap: wrap; }
.ins-tab { padding: 7px 14px; border-radius: 999px; border: 1px solid var(--line, #2a3b34);
  background: transparent; color: inherit; font-size: 13px; cursor: pointer; }
.ins-tab.is-active { background: var(--accent, #16d1a5); color: #062018; border-color: transparent; font-weight: 600; }
.ins-custom { display: flex; gap: 8px; margin: 0 0 12px; flex-wrap: wrap; align-items: center; }
.ins-custom input { padding: 7px 10px; border-radius: 8px; border: 1px solid var(--line, #2a3b34);
  background: transparent; color: inherit; }
.ins-custom-go { padding: 7px 14px; border-radius: 8px; border: none; background: var(--accent, #16d1a5);
  color: #062018; font-weight: 600; cursor: pointer; }
.ins-custom-go:disabled { opacity: .5; cursor: default; }
.ins-history { display: flex; gap: 6px; margin: 0 0 12px; overflow-x: auto; padding-bottom: 2px; }
.ins-hist-item { flex: 0 0 auto; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line, #2a3b34);
  background: transparent; color: inherit; font-size: 12.5px; cursor: pointer; white-space: nowrap; }
.ins-hist-item.is-active { border-color: var(--accent, #16d1a5); color: var(--accent, #16d1a5); font-weight: 600; }
```

- [ ] **Step 5: Run the frontend test + typecheck**

Run (in `admin/`): `npx vitest run src/pages/AiSummary.periods.test.tsx` then `npx tsc --noEmit`
Expected: PASS + no type errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/AiSummary.tsx admin/src/styles/insights.css admin/src/pages/AiSummary.periods.test.tsx
git commit -m "feat(admin): AI summary period selector, history browsing, custom range"
```

---

## Deployment (out of scope for these tasks)

Backend needs the migration (`php artisan migrate --force`) on staging then prod. Frontend needs an admin build+deploy (staging via `VITE_API_URL=...staging... npm run build` → `/var/www/admin-staging`; prod via `admin/deploy.ps1`). Promote per the standing flow once green — Francis triggers it.

## Self-Review

- **Spec coverage:** period_type model → Task 1; period-aware writer → Task 2; period param + history endpoint + lib → Task 3; weekly/monthly commands + trait + scheduler → Task 4; frontend selector/history/custom → Task 5. All spec sections mapped.
- **Regression safety:** writer `periodType` defaults to `custom`; existing writer tests keep passing (same window → exact `storedFor`); daily command keeps emitting "N generated" after the trait refactor; rolling30 retains the `latestStored` fallback.
- **Cross-DB:** migration uses only `dropUnique`/`unique`; no raw SQL.
- **Type consistency:** `summary(...,$periodType='custom')` used consistently by controller + both commands; `storedFor`/`latestStored($shopId,$periodType)`/`persist(...,$periodType)` signatures align; frontend `getAiInsights(...period)` / `getAiSummaryHistory(shopId,periodType,page)` match the lib and the tests.
- **Placeholder scan:** none — full code and exact commands throughout.
