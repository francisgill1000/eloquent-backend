# AI Insights Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an AI-generated, plain-language performance summary (2–3 sentence overview, 2–3 patterns, 1–2 recommendations) to the Insights page and the owner voice assistant, reusing existing Claude + reporting infrastructure.

**Architecture:** A new `AiInsightsWriter` service assembles a numbers-only metrics payload from `ReportsAggregator` (selected period + previous equal-length period), calls the existing `ClaudeClient`, and parses/validates the model's JSON. A new `GET /shop/reports/ai-summary` endpoint and an owner-assistant read tool both call it. A card on `Insights.tsx` renders the result with loading/refresh/low-data/error states.

**Tech Stack:** Laravel (PHP 8.4), PHPUnit feature tests, React + TypeScript (Vite admin app), Anthropic Messages API via `App\Services\Wa\ClaudeClient`.

## Global Constraints

- Tests run on the droplet (php8.4) ONLY — never local. NEVER against the prod DB: dump first, use a scratch DB, verify the connection before running.
- Deploy to STAGING only. Never touch prod.
- No new AI integration. Reuse `ClaudeClient` and `ReportsAggregator` unchanged (no internal edits to either).
- Model id comes from `config('services.anthropic.model')` (env `CLAUDE_MODEL`) via `ClaudeClient` — never hardcode a model id.
- Cache TTL: 24 hours (86400s), key `ai_insights:{shopId}:{from}:{to}` with dates as `Y-m-d`.
- Low-data gate: selected-period `bookings.scheduled < 5` → fixed `low_data` state, no Claude call, not cached.
- `error` and `low_data` states are NEVER cached; only `ok` is cached.
- Multi-tenant: every metrics call scoped by `shopId`; no cross-shop leakage.
- Writer return shape (all states): `state`, `summary`, `patterns`, `recommendations`, `message`, `generated_at`, `cached`.

---

### Task 1: `AiInsightsWriter` service

**Files:**
- Create: `app/Services/Reports/AiInsightsWriter.php`
- Test: `tests/Feature/AiInsightsWriterTest.php`

**Interfaces:**
- Consumes: `App\Services\Reports\ReportsAggregator::insightsSummary(int,Carbon,Carbon): array`, `::revenueSummary(int,Carbon,Carbon): array`; `App\Services\Wa\ClaudeClient::reply(string $system, array $history): string`.
- Produces: `AiInsightsWriter::summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh = false): array` returning the shape defined in Global Constraints. Later tasks (endpoint, voice tool) depend on this signature and shape.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AiInsightsWriterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Reports\AiInsightsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInsightsWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function shop(string $code = '7001'): Shop
    {
        return Shop::create([
            'name' => 'Test Salon', 'shop_code' => $code, 'pin' => '0000',
            'status' => 'active', 'category_id' => 11,
        ]);
    }

    private function seedBookings(Shop $shop, int $count, string $status = 'completed'): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'shop_id' => $shop->id, 'date' => now()->toDateString(),
                'start_time' => '10:00', 'end_time' => '10:30', 'status' => $status,
                'charges' => 50, 'discount_amount' => 0,
                'services' => json_encode([['id' => 1, 'title' => 'Haircut', 'price' => '50.00']]),
                'booking_reference' => 'BK' . $shop->id . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_name' => 'Cust ' . $i,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        \DB::table('bookings')->insert($rows);
    }

    private function writer(): AiInsightsWriter
    {
        return app(AiInsightsWriter::class);
    }

    private function fakeClaude(array $json): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($json)]],
            ], 200),
        ]);
    }

    public function test_happy_path_returns_validated_and_clamped_shape(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 6);
        $this->fakeClaude([
            'summary' => 'You had a strong month.',
            'patterns' => ['More completed visits', 'Repeat customers up', 'Third pattern', 'Overflow ignored'],
            'recommendations' => ['Ask for reviews', 'Fill quiet mornings', 'Overflow ignored'],
        ]);

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('ok', $out['state']);
        $this->assertSame('You had a strong month.', $out['summary']);
        $this->assertCount(3, $out['patterns']);
        $this->assertCount(2, $out['recommendations']);
        $this->assertFalse($out['cached']);
        $this->assertArrayHasKey('generated_at', $out);
    }

    public function test_low_data_skips_claude_call(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 4); // < 5 scheduled
        Http::fake();

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('low_data', $out['state']);
        $this->assertNotSame('', $out['message']);
        Http::assertNothingSent();
    }

    public function test_malformed_json_returns_error_state_and_is_not_cached(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 6);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'sorry, no json here']],
            ], 200),
        ]);

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame('error', $out['state']);

        // Second call still hits the model (error was not cached).
        $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        Http::assertSentCount(2);
    }

    public function test_cache_hit_avoids_second_call_and_force_refresh_bypasses(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 6);
        $this->fakeClaude(['summary' => 'Cached.', 'patterns' => ['a'], 'recommendations' => ['b']]);

        $first = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertFalse($first['cached']);

        $second = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertTrue($second['cached']);
        Http::assertSentCount(1);

        $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth(), true);
        Http::assertSentCount(2);
    }

    public function test_scoped_to_shop(): void
    {
        $shop = $this->shop('7001');
        $other = $this->shop('7002');
        $this->seedBookings($other, 10); // only the OTHER shop has data
        Http::fake();

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('low_data', $out['state']);
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (on the droplet, scratch DB): `php artisan test --filter=AiInsightsWriterTest`
Expected: FAIL — `Class "App\Services\Reports\AiInsightsWriter" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Services/Reports/AiInsightsWriter.php`:

```php
<?php

namespace App\Services\Reports;

use App\Services\Wa\ClaudeClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Builds a numbers-only metrics payload (selected period vs the previous
 * equal-length period), asks ClaudeClient for a short plain-language narrative,
 * and returns validated JSON. Result is cached per shop_id+from+to for 24h.
 * Every metrics call is scoped by shop_id — no cross-shop leakage.
 */
class AiInsightsWriter
{
    private const CACHE_TTL   = 86400; // 24h
    private const MIN_BOOKINGS = 5;

    public function __construct(
        protected ReportsAggregator $aggregator,
        protected ClaudeClient $claude,
    ) {}

    public function summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh = false): array
    {
        $key = sprintf('ai_insights:%d:%s:%s', $shopId, $from->toDateString(), $to->toDateString());

        if (! $forceRefresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return array_merge($cached, ['cached' => true]);
            }
        }

        $insights = $this->aggregator->insightsSummary($shopId, $from, $to);

        if ((int) ($insights['bookings']['scheduled'] ?? 0) < self::MIN_BOOKINGS) {
            return $this->state('low_data', 'Not enough bookings in this period yet to generate an AI summary. Check back once you have a few more.');
        }

        try {
            $payload = $this->buildPayload($shopId, $from, $to, $insights);
            $reply = $this->claude->reply($this->systemPrompt(), [
                ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
            ]);
            $parsed = $this->parse($reply);
        } catch (\Throwable $e) {
            Log::warning('AiInsightsWriter failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
            return $this->state('error', 'Could not generate the AI summary right now. Please try again.');
        }

        if ($parsed === null) {
            return $this->state('error', 'Could not generate the AI summary right now. Please try again.');
        }

        $result = [
            'state'           => 'ok',
            'summary'         => $parsed['summary'],
            'patterns'        => $parsed['patterns'],
            'recommendations' => $parsed['recommendations'],
            'message'         => '',
            'generated_at'    => Carbon::now()->toIso8601String(),
            'cached'          => false,
        ];

        Cache::put($key, $result, self::CACHE_TTL);

        return $result;
    }

    protected function buildPayload(int $shopId, Carbon $from, Carbon $to, array $insights): array
    {
        $lengthDays = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo   = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($lengthDays - 1)->startOfDay();

        $revenue      = $this->aggregator->revenueSummary($shopId, $from, $to);
        $prevRevenue  = $this->aggregator->revenueSummary($shopId, $prevFrom, $prevTo);
        $prevInsights = $this->aggregator->insightsSummary($shopId, $prevFrom, $prevTo);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $lengthDays],
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

    /** @return array{summary: string, patterns: string[], recommendations: string[]}|null */
    protected function parse(string $reply): ?array
    {
        $start = strpos($reply, '{');
        $end   = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($reply, $start, $end - $start + 1), true);
        if (! is_array($data)
            || ! isset($data['summary'], $data['patterns'], $data['recommendations'])
            || ! is_string($data['summary'])
            || ! is_array($data['patterns'])
            || ! is_array($data['recommendations'])
        ) {
            return null;
        }

        $strings = fn (array $a) => array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            $a,
        )));

        $summary = trim($data['summary']);
        if ($summary === '') {
            return null;
        }

        return [
            'summary'         => $summary,
            'patterns'        => array_slice($strings($data['patterns']), 0, 3),
            'recommendations' => array_slice($strings($data['recommendations']), 0, 2),
        ];
    }

    protected function state(string $state, string $message): array
    {
        return [
            'state'           => $state,
            'summary'         => '',
            'patterns'        => [],
            'recommendations' => [],
            'message'         => $message,
            'generated_at'    => Carbon::now()->toIso8601String(),
            'cached'          => false,
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a plain-language business analyst for a service business (salon, clinic, laundry, etc.).
You will receive a JSON object of computed metrics for the selected period and the previous equal-length period.

Write a short, encouraging but honest performance summary for the shop owner, who is NOT technical.

STRICT RULES:
- Use ONLY the numbers provided. Never invent figures, names, or trends the data does not show.
- Compare "current" vs "previous" to describe direction (up / down / flat). If a previous value is zero, describe it as a new or first-of-period result rather than citing a percentage change.
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
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=AiInsightsWriterTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/AiInsightsWriter.php tests/Feature/AiInsightsWriterTest.php
git commit -m "feat(insights): AiInsightsWriter builds & validates AI narrative summary

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `GET /shop/reports/ai-summary` endpoint

**Files:**
- Modify: `app/Http/Controllers/ReportsController.php` (add `use` import + `aiSummary` method)
- Modify: `routes/api.php:98` (add route after the `insights` route)
- Test: `tests/Feature/AiInsightsEndpointTest.php`

**Interfaces:**
- Consumes: `AiInsightsWriter::summary(...)` from Task 1; `ReportsController::validated(Request): array` (existing, returns `[int $shopId, Carbon $from, Carbon $to]`).
- Produces: JSON HTTP endpoint `GET /api/shop/reports/ai-summary?shop_id&from&to[&refresh=1]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AiInsightsEndpointTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInsightsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    public function test_requires_shop_id(): void
    {
        $this->getJson('/api/shop/reports/ai-summary?from=2026-07-01&to=2026-07-31')
            ->assertStatus(422);
    }

    public function test_returns_low_data_for_empty_shop(): void
    {
        $shop = Shop::factory()->create();
        Http::fake();

        $res = $this->getJson('/api/shop/reports/ai-summary?shop_id=' . $shop->id
            . '&from=' . now()->startOfMonth()->toDateString()
            . '&to=' . now()->endOfMonth()->toDateString())
            ->assertOk();

        $this->assertSame('low_data', $res->json('state'));
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AiInsightsEndpointTest`
Expected: FAIL — 404 (route not defined) on the second test.

- [ ] **Step 3: Add the import and controller method**

In `app/Http/Controllers/ReportsController.php`, add the import near the top (after `use App\Services\Reports\ReportsAggregator;`):

```php
use App\Services\Reports\AiInsightsWriter;
```

Add this method after the existing `insights()` method (around line 46):

```php
    public function aiSummary(Request $request, AiInsightsWriter $writer)
    {
        [$shopId, $from, $to] = $this->validated($request);
        return response()->json($writer->summary($shopId, $from, $to, $request->boolean('refresh')));
    }
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, after line 98 (`/shop/reports/insights`), add:

```php
Route::get('/shop/reports/ai-summary',    [\App\Http\Controllers\ReportsController::class, 'aiSummary']);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=AiInsightsEndpointTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ReportsController.php routes/api.php tests/Feature/AiInsightsEndpointTest.php
git commit -m "feat(insights): GET /shop/reports/ai-summary endpoint

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: `get_ai_summary` owner-assistant voice tool

**Files:**
- Modify: `app/Services/Assistant/OwnerAssistantTools.php` (constructor, `defs()`, `execute()`, new `aiSummary` method, import)
- Modify: `tests/Feature/OwnerAssistantToolsTest.php` (fix the `tools()` helper for the new constructor dependency)
- Modify: `tests/Feature/OwnerAssistantMutationTest.php` (same helper fix — it also constructs the tool directly)
- Test: `tests/Feature/OwnerAssistantAiSummaryTest.php`

**Interfaces:**
- Consumes: `AiInsightsWriter::summary(...)` from Task 1; `App\Support\Assistant\PeriodResolver::resolve(string $period): array` (existing, returns `[Carbon $from, Carbon $to]`).
- Produces: assistant tool `get_ai_summary` (input: `{ period: enum }`) returning the writer's array; routed via `OwnerAssistantTools::execute()` and already surfaced through `AssistantToolRegistry` (no registry change).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OwnerAssistantAiSummaryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Assistant\OwnerAssistantTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OwnerAssistantAiSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function seedShopWithBookings(int $count): Shop
    {
        $shop = Shop::create([
            'name' => 'Voice Salon', 'shop_code' => '7101', 'pin' => '0000',
            'status' => 'active', 'category_id' => 11,
        ]);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'shop_id' => $shop->id, 'date' => now()->toDateString(),
                'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'completed',
                'charges' => 50, 'discount_amount' => 0,
                'services' => json_encode([['id' => 1, 'title' => 'Haircut', 'price' => '50.00']]),
                'booking_reference' => 'BKV' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_name' => 'Cust ' . $i,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        \DB::table('bookings')->insert($rows);
        return $shop;
    }

    public function test_get_ai_summary_is_registered_and_returns_summary(): void
    {
        $shop = $this->seedShopWithBookings(6);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'Busy month overall.',
                    'patterns' => ['More completed visits'],
                    'recommendations' => ['Ask happy clients for reviews'],
                ])]],
            ], 200),
        ]);

        $tools = app(OwnerAssistantTools::class);

        $names = array_column($tools->toolDefs(), 'name');
        $this->assertContains('get_ai_summary', $names);

        $out = json_decode($tools->execute($shop, 'get_ai_summary', ['period' => 'this_month']), true);
        $this->assertSame('ok', $out['state']);
        $this->assertSame('Busy month overall.', $out['summary']);
    }

    public function test_get_ai_summary_low_data_is_scoped_to_shop(): void
    {
        $shop = $this->seedShopWithBookings(2); // < 5
        Http::fake();

        $out = json_decode(app(OwnerAssistantTools::class)->execute($shop, 'get_ai_summary', ['period' => 'this_month']), true);

        $this->assertSame('low_data', $out['state']);
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=OwnerAssistantAiSummaryTest`
Expected: FAIL — `get_ai_summary` not in defs / `unknown tool get_ai_summary`.

- [ ] **Step 3: Add the dependency and tool**

In `app/Services/Assistant/OwnerAssistantTools.php`:

Add the import after `use App\Services\Reports\ReportsAggregator;`:

```php
use App\Services\Reports\AiInsightsWriter;
```

Change the constructor:

```php
    public function __construct(
        protected ReportsAggregator $aggregator,
        protected AiInsightsWriter $writer,
    ) {}
```

In `defs()`, add this entry to the returned array (after the `get_busy_times` entry):

```php
            [
                'name' => 'get_ai_summary',
                'description' => 'AI-written plain-language performance summary for a period: a short overview, notable patterns, and recommendations. Use for "how are we doing" / "give me my AI summary".',
                'input_schema' => ['type' => 'object', 'properties' => ['period' => $period], 'required' => ['period']],
            ],
```

In `execute()`, add this arm to the `match` (after `'get_busy_times' => ...`):

```php
            'get_ai_summary'        => $this->aiSummary($shop, $input),
```

Add this method (after the `aggregatorFor` method):

```php
    protected function aiSummary(Shop $shop, array $input): array
    {
        [$from, $to] = \App\Support\Assistant\PeriodResolver::resolve($input['period'] ?? 'this_month');
        return $this->writer->summary($shop->id, $from, $to);
    }
```

- [ ] **Step 4: Fix the existing test helpers for the new constructor**

Two existing test files build the tool directly with a single argument, which would now be missing the second constructor argument. In BOTH `tests/Feature/OwnerAssistantToolsTest.php` and `tests/Feature/OwnerAssistantMutationTest.php`, replace the line:

```php
        return new OwnerAssistantTools(app(ReportsAggregator::class));
```

with:

```php
        return app(OwnerAssistantTools::class);
```

(The unused `ReportsAggregator` imports may stay — harmless.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=OwnerAssistantAiSummaryTest`
Expected: PASS (2 tests).
Run: `php artisan test --filter=OwnerAssistantToolsTest`
Expected: PASS (regression check — the constructor change didn't break the legacy tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Assistant/OwnerAssistantTools.php tests/Feature/OwnerAssistantAiSummaryTest.php tests/Feature/OwnerAssistantToolsTest.php tests/Feature/OwnerAssistantMutationTest.php
git commit -m "feat(assistant): get_ai_summary voice tool returns AI insights

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Frontend — API client + AI Insights card

**Files:**
- Create: `admin/src/lib/aiInsights.ts`
- Modify: `admin/src/pages/Insights.tsx` (import, state, fetch effect, card component, render)
- Modify: `admin/src/styles/insights.css` (append `ins-ai-*` styles)

**Interfaces:**
- Consumes: `GET /shop/reports/ai-summary` from Task 2; existing `api` client (`admin/src/lib/api.ts`), `useShop()` context, `Icons`.
- Produces: `getAiInsights(shopId, from, to, refresh?)` and the `AiInsights` type; a card rendered at the top of the Insights report.

- [ ] **Step 1: Create the API client**

Create `admin/src/lib/aiInsights.ts`:

```ts
import api from './api';

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
  shopId: number,
  from: string,
  to: string,
  refresh = false,
): Promise<AiInsights> {
  const { data } = await api.get('/shop/reports/ai-summary', {
    params: { shop_id: shopId, from, to, ...(refresh ? { refresh: 1 } : {}) },
  });
  return data;
}
```

- [ ] **Step 2: Wire the card into `Insights.tsx`**

Add the import near the other lib imports (after the `getInsights` import):

```tsx
import { getAiInsights, type AiInsights } from '@/lib/aiInsights';
```

Add the `AiInsightsCard` component just above the `export default function Insights()` line:

```tsx
/* ---------- AI Insights card ------------------------------------------------ */
function AiInsightsCard({ data, loading, refreshing, onRefresh }: {
  data: AiInsights | null; loading: boolean; refreshing: boolean; onRefresh: () => void;
}) {
  return (
    <div className="ins-card span2 ins-ai">
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icons.Sparkle size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">AI summary</span>
          <span className="ins-card-sub">Plain-language read on this period</span>
        </span>
        <button className="ins-ai-refresh" onClick={onRefresh} disabled={loading || refreshing}
          aria-label="Refresh AI summary">
          {refreshing ? 'Refreshing…' : 'Refresh'}
        </button>
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
```

Inside the `Insights()` component, add the following block immediately AFTER the normalized-range declarations (`const nf = from <= to ? from : to;` / `const nt = from <= to ? to : from;`) and before the existing `fetchData` callback — `fetchAi` closes over `nf`/`nt`, so it must come after they are defined:

```tsx
  const [aiData, setAiData] = useState<AiInsights | null>(null);
  const [aiLoading, setAiLoading] = useState(true);
  const [aiRefreshing, setAiRefreshing] = useState(false);

  const fetchAi = useCallback(async (refresh = false) => {
    if (!shop?.id) return;
    refresh ? setAiRefreshing(true) : setAiLoading(true);
    try {
      const res = await getAiInsights(shop.id, nf, nt, refresh);
      setAiData(res);
    } catch {
      setAiData({ state: 'error', summary: '', patterns: [], recommendations: [],
        message: 'Could not generate the AI summary right now.', generated_at: '', cached: false });
    } finally {
      setAiLoading(false); setAiRefreshing(false);
    }
  }, [shop?.id, nf, nt]);

  useEffect(() => { void fetchAi(false); }, [fetchAi]);
```

Render the card inside the `ins-wrap` div, immediately after the closing `</div>` of the `ins-filter` block and before `{error && ...}`:

```tsx
        <AiInsightsCard data={aiData} loading={aiLoading} refreshing={aiRefreshing}
          onRefresh={() => fetchAi(true)} />
```

- [ ] **Step 3: Confirm the header icon exists**

Run: `grep -nE "Sparkle:" admin/src/components/Icons.tsx`
Expected: `Sparkle` is an exported key of `Icons` (confirmed present). The Refresh control is text-only (no icon), so no other icon is needed. If `Sparkle` is somehow absent, substitute `Icons.Chart` (present) with the same `Icons.<Name> size={17}` call shape.

- [ ] **Step 4: Append the card styles**

Append to `admin/src/styles/insights.css`:

```css
/* ---------- AI summary card ------------------------------------------------ */
.ins-ai .ins-card-head { align-items: center; }
.ins-ai-refresh {
  margin-left: auto; display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: 999px; font-size: 13px; font-weight: 600;
  color: var(--mint-300); background: var(--neutral-soft);
  border: 1px solid var(--border-3); cursor: pointer;
}
.ins-ai-refresh:disabled { opacity: 0.5; cursor: default; }
.ins-ai-body { padding-top: 4px; transition: opacity 0.2s ease; }
.ins-ai-body.is-refreshing { opacity: 0.5; }
.ins-ai-summary { font-size: 15px; line-height: 1.55; color: var(--text-1); margin: 0 0 14px; }
.ins-ai-block { margin-top: 12px; }
.ins-ai-label {
  display: block; font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase;
  color: var(--text-3); margin-bottom: 6px;
}
.ins-ai-list { margin: 0; padding-left: 18px; }
.ins-ai-list li { font-size: 14px; line-height: 1.5; color: var(--text-2); margin-bottom: 4px; }
.ins-ai-msg { font-size: 14px; color: var(--text-2); margin: 0 0 12px; }
.ins-ai-retry {
  font-size: 13px; font-weight: 600; color: var(--mint-300);
  background: none; border: none; padding: 0; cursor: pointer;
}
```

- [ ] **Step 5: Build the admin app to verify it compiles**

Run: `cd admin && npm run build`
Expected: build succeeds with no TypeScript errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src/lib/aiInsights.ts admin/src/pages/Insights.tsx admin/src/styles/insights.css
git commit -m "feat(insights): AI summary card on the Insights page

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Full test run + STAGING deploy

**Files:** none (verification + deploy only).

- [ ] **Step 1: Run the full backend suite on the droplet (scratch DB)**

First confirm the DB connection is a scratch DB, NOT prod (per Global Constraints — verify `.env` DB name / `php artisan db:show` before running). Then:

Run: `php artisan test`
Expected: green — the pre-existing suite plus the new `AiInsightsWriterTest`, `AiInsightsEndpointTest`, and `OwnerAssistantAiSummaryTest`. Note the before/after passing counts.

- [ ] **Step 2: Confirm the admin build is clean**

Run: `cd admin && npm run build`
Expected: succeeds.

- [ ] **Step 3: Deploy to STAGING only**

Use the project's staging deploy process — load the `deploy-eloquent-app` skill for the exact backend steps to the staging host (64.227.153.90), and deploy the admin frontend with `admin/deploy.ps1` (per project convention). Do NOT deploy to prod.

- [ ] **Step 4: Smoke-test on STAGING**

- Open the Insights page for a shop with ≥5 bookings in the range → the AI summary card renders a narrative; Refresh regenerates.
- Open it for a shop/range with <5 bookings → the "not enough data yet" state shows and no Claude call is made.
- Ask the owner assistant "how are we doing / give me my AI summary" → it returns the narrative.

- [ ] **Step 5: Commit any staging config touch-ups (if needed) and stop**

Do not promote to prod. Report the staging result (before/after test counts, smoke-test outcome) back for review.
```
