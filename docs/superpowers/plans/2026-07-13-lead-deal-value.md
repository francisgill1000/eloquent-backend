# Lead Deal Value Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture the money value of a deal when a Business Hunt lead is won, and surface total won revenue (and its shape) in the pipeline reporting and the Leads page.

**Architecture:** Add four nullable `deal_*` columns to `leads`; capture them through the existing `PATCH /shop/leads/{lead}/status` endpoint when a lead reaches `won`; expose a computed `deal_total` accessor; aggregate won value in `ReportsAggregator::huntSummary` (attributed by `deal_won_at`, current status `won` only); feed the numbers into the nightly AI summary and show a won-value total on the Leads page.

**Tech Stack:** Laravel 11 (PHP 8.4), Eloquent, Pest/PHPUnit feature tests with `RefreshDatabase`; React + TypeScript admin SPA (Vite, Vitest, axios).

## Global Constraints

- **Not a CRM.** No deal objects, no weighted/expected pipeline value, no close-date forecasting, no deal-history/audit table. One flat `leads` row.
- **Currency is AED only.** No multi-currency.
- **Amount semantics:** `deal_amount` is the **monthly** price when `deal_type = 'recurring'`, and the **whole** amount when `deal_type = 'one_off'`.
- **`deal_total` is derived, never stored** — computed accessor only, so it cannot drift.
- **Period attribution is by `deal_won_at`**, never `created_at`, and counts once.
- **Reversed deals** (won → later moved to `pass`) keep their `deal_*` data on the row but are **excluded** from won-value aggregation (only leads whose *current* status is `won` count).
- **Capture is optional** — a lead can be marked `won` with no amount; it counts toward the won *count* but contributes `0` to won value.
- **Tenant isolation:** every query is scoped by `shop_id`; the shop id is never read from the request body.
- **PHP tests run on the droplet / staging (php8.4), never locally** — local PHP is broken. Use a scratch DB, never the prod DB. Frontend tests run locally with `npm test` (Vitest) in `admin/`.
- **Deal term options:** `deal_term_months` ∈ `{1, 3, 6, 12}`. `deal_type` ∈ `{one_off, recurring}`.

---

### Task 1: Migration — add deal columns to `leads`

**Files:**
- Create: `database/migrations/2026_07_13_000001_add_deal_value_to_leads_table.php`

**Interfaces:**
- Produces: columns `leads.deal_amount` (decimal 10,2 nullable), `leads.deal_type` (string nullable), `leads.deal_term_months` (unsignedSmallInteger nullable), `leads.deal_won_at` (timestamp nullable).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Deal value captured when a lead is won. deal_amount is the MONTHLY
            // price for a recurring deal, or the whole amount for a one-off.
            $table->decimal('deal_amount', 10, 2)->nullable()->after('status');
            $table->string('deal_type')->nullable()->after('deal_amount');           // one_off|recurring
            $table->unsignedSmallInteger('deal_term_months')->nullable()->after('deal_type'); // 1|3|6|12 (recurring only)
            $table->timestamp('deal_won_at')->nullable()->after('deal_term_months');  // period attribution
            $table->index(['shop_id', 'status', 'deal_won_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'status', 'deal_won_at']);
            $table->dropColumn(['deal_amount', 'deal_type', 'deal_term_months', 'deal_won_at']);
        });
    }
};
```

- [ ] **Step 2: Run the migration on the scratch/staging DB**

Run: `php artisan migrate` (on the droplet/staging, scratch DB)
Expected: migration runs green; `leads` table gains the four columns.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_13_000001_add_deal_value_to_leads_table.php
git commit -m "feat(leads): add deal value columns (amount, type, term, won_at)"
```

---

### Task 2: `Lead` model — constants, fillable, casts, appends, `deal_total`

**Files:**
- Modify: `app/Models/Lead.php`
- Test: `tests/Feature/LeadDealValueTest.php` (new; model-level cases)

**Interfaces:**
- Consumes: columns from Task 1.
- Produces:
  - `Lead::DEAL_TYPES = ['one_off', 'recurring']`
  - `Lead::DEAL_TERMS = [1, 3, 6, 12]`
  - Accessor `getDealTotalAttribute(): ?float` → `deal_amount` for one-off, `deal_amount * deal_term_months` for recurring, `null` when `deal_amount` is null.
  - `deal_total` present in serialized output (via `$appends`); `deal_won_at` cast to `datetime`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadDealValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_total_is_amount_for_one_off(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'A', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off',
        ]);

        $this->assertSame(500.0, $lead->deal_total);
        $this->assertArrayHasKey('deal_total', $lead->fresh()->toArray());
    }

    public function test_deal_total_multiplies_monthly_by_term_for_recurring(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'B', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6,
        ]);

        $this->assertSame(1800.0, $lead->deal_total);
    }

    public function test_deal_total_is_null_without_amount(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'C', 'status' => 'won']);

        $this->assertNull($lead->deal_total);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeadDealValueTest` (droplet/staging scratch DB)
Expected: FAIL — `deal_total` is null / column not fillable.

- [ ] **Step 3: Edit `app/Models/Lead.php`**

Add the constants below the existing `STATUSES` const:

```php
    /** Deal shape captured at win time. */
    public const DEAL_TYPES = ['one_off', 'recurring'];

    /** Allowed recurring contract terms, in months. */
    public const DEAL_TERMS = [1, 3, 6, 12];
```

Add the four columns to `$fillable` (after `'status',`):

```php
        'deal_amount',
        'deal_type',
        'deal_term_months',
        'deal_won_at',
```

Add casts (inside `$casts`):

```php
        'deal_amount' => 'float',
        'deal_term_months' => 'integer',
        'deal_won_at' => 'datetime',
```

Add `deal_total` to `$appends`:

```php
    protected $appends = ['whatsapp_url', 'is_mobile', 'tel_url', 'map_url', 'deal_total'];
```

Add the accessor (near the other accessors):

```php
    /**
     * Derived contract value of a won deal (never stored, so it can't drift):
     * the amount itself for a one-off, or monthly amount × term for recurring.
     * Null when no amount was captured.
     */
    public function getDealTotalAttribute(): ?float
    {
        if ($this->deal_amount === null) {
            return null;
        }
        if ($this->deal_type === 'recurring') {
            return round((float) $this->deal_amount * (int) ($this->deal_term_months ?? 0), 2);
        }
        return round((float) $this->deal_amount, 2);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LeadDealValueTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Lead.php tests/Feature/LeadDealValueTest.php
git commit -m "feat(leads): deal_total accessor + deal fields on Lead model"
```

---

### Task 3: Capture the deal in `updateStatus`

**Files:**
- Modify: `app/Http/Controllers/LeadController.php:308-334` (`updateStatus`)
- Test: `tests/Feature/LeadDealValueTest.php` (add endpoint cases)

**Interfaces:**
- Consumes: `Lead::DEAL_TYPES`, `Lead::DEAL_TERMS` (Task 2); `deal_total` accessor.
- Produces: `PATCH /shop/leads/{lead}/status` now accepts optional `deal_amount` (numeric ≥ 0), `deal_type` (in `DEAL_TYPES`), `deal_term_months` (in `DEAL_TERMS`). When the new status is `won`, it sets `deal_won_at` (once) and stores the deal fields. Recurring requires a term; one-off forces the term to null.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LeadDealValueTest.php`. Add the auth helpers at the top of the class (same pattern as `LeadFollowupTest`):

```php
    use \App\Models\ShopUser;

    /** @return array{0: Shop, 1: string} */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();
        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_marking_won_stores_recurring_deal_and_sets_won_at(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'D', 'status' => 'demo']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6,
        ])->assertOk()->assertJsonPath('data.deal_total', 1800);

        $fresh = $lead->fresh();
        $this->assertSame('won', $fresh->status);
        $this->assertNotNull($fresh->deal_won_at);
    }

    public function test_one_off_win_nulls_the_term(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'E', 'status' => 'demo']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_term_months' => 6,
        ])->assertOk()->assertJsonPath('data.deal_total', 500);

        $this->assertNull($lead->fresh()->deal_term_months);
    }

    public function test_recurring_requires_a_term(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'F', 'status' => 'demo']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => 300, 'deal_type' => 'recurring',
        ])->assertStatus(422);
    }

    public function test_can_win_without_an_amount(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'G', 'status' => 'demo']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", ['status' => 'won'])
            ->assertOk()->assertJsonPath('data.deal_total', null);

        $this->assertNotNull($lead->fresh()->deal_won_at);
    }

    public function test_rewinning_does_not_reset_won_at(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'H', 'status' => 'won',
            'deal_won_at' => now()->subDays(10),
        ]);
        $originalWonAt = $lead->deal_won_at->toDateTimeString();

        // Move away and back.
        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", ['status' => 'replied'])->assertOk();
        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => 200, 'deal_type' => 'one_off',
        ])->assertOk();

        $this->assertSame($originalWonAt, $lead->fresh()->deal_won_at->toDateTimeString());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=LeadDealValueTest`
Expected: FAIL — deal fields ignored, `deal_won_at` never set, no 422 for missing term.

- [ ] **Step 3: Rewrite `updateStatus`**

Replace the body of `updateStatus` in `app/Http/Controllers/LeadController.php` with:

```php
    public function updateStatus(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
            // Deal value is only meaningful when winning; all optional so the
            // funnel is never blocked on money.
            'deal_amount' => ['nullable', 'numeric', 'min:0'],
            'deal_type' => ['nullable', Rule::in(Lead::DEAL_TYPES)],
            // A recurring deal must carry a term; a one-off must not.
            'deal_term_months' => [
                'nullable',
                Rule::requiredIf(fn () => ($request->input('deal_type') === 'recurring')),
                Rule::in(Lead::DEAL_TERMS),
            ],
        ]);

        $from = $lead->status;
        $lead->status = $data['status'];
        $lead->last_contacted_at = now();

        // Capture / update the deal only on a win. deal_won_at is stamped once
        // (first win) so re-winning a lead keeps its original won date.
        if ($data['status'] === 'won') {
            if (array_key_exists('deal_amount', $data)) {
                $lead->deal_amount = $data['deal_amount'];
                $lead->deal_type = $data['deal_type'] ?? 'one_off';
                $lead->deal_term_months = ($lead->deal_type === 'recurring')
                    ? ($data['deal_term_months'] ?? null)
                    : null;
            }
            $lead->deal_won_at = $lead->deal_won_at ?? now();
        }

        $lead->save();

        $lead->activities()->create([
            'type' => LeadActivity::TYPE_STATUS_CHANGE,
            'payload' => array_filter([
                'from' => $from,
                'to' => $data['status'],
                'note' => $data['note'] ?? null,
            ], fn ($v) => $v !== null),
            'user_id' => current_shop_user()?->id,
        ]);

        return response()->json(['data' => $lead->fresh()]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=LeadDealValueTest`
Expected: PASS (all model + endpoint cases).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LeadController.php tests/Feature/LeadDealValueTest.php
git commit -m "feat(leads): capture deal value when a lead is won"
```

---

### Task 4: Aggregate won value in `huntSummary`

**Files:**
- Modify: `app/Services/Reports/ReportsAggregator.php:316-372` (`huntSummary`)
- Test: `tests/Feature/ReportsAggregatorHuntTest.php` (add a won-value case)

**Interfaces:**
- Consumes: `deal_amount`, `deal_type`, `deal_term_months`, `deal_won_at` columns.
- Produces: `huntSummary(...)` return array gains `won_value` (float), `won_value_recurring` (float), `won_value_one_off` (float), `mrr_won` (float). All attributed by `deal_won_at ∈ [from, to]` and restricted to leads whose current `status = 'won'`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ReportsAggregatorHuntTest.php`:

```php
    public function test_hunt_summary_reports_won_value_split_and_mrr(): void
    {
        $shop = $this->shop('8004');

        // One-off won this period: AED 500.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'One', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now(),
        ]);
        // Recurring won this period: 300/mo × 6 = 1800 total, 300 MRR.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'Rec', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'deal_won_at' => now(),
        ]);
        // Won without an amount — counts in `won`, contributes 0 value.
        Lead::create(['shop_id' => $shop->id, 'name' => 'Blank', 'status' => 'won', 'deal_won_at' => now()]);
        // Reversed deal: was won, now passed — must be EXCLUDED from value.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'Lost', 'status' => 'pass',
            'deal_amount' => 9999, 'deal_type' => 'one_off', 'deal_won_at' => now(),
        ]);
        // Won but OUTSIDE the period (won last month) — excluded.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'Old', 'status' => 'won',
            'deal_amount' => 7777, 'deal_type' => 'one_off', 'deal_won_at' => now()->subMonthNoOverflow()->subDays(2),
        ]);

        $out = app(ReportsAggregator::class)->huntSummary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(500.0 + 1800.0, $out['won_value']);
        $this->assertSame(1800.0, $out['won_value_recurring']);
        $this->assertSame(500.0, $out['won_value_one_off']);
        $this->assertSame(300.0, $out['mrr_won']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReportsAggregatorHuntTest`
Expected: FAIL — `won_value` key is undefined.

- [ ] **Step 3: Add the aggregation to `huntSummary`**

In `app/Services/Reports/ReportsAggregator.php`, before the `return [` of `huntSummary`, add:

```php
        // Won-deal value this period: attributed by deal_won_at, and only for
        // leads whose CURRENT status is 'won' (a reversed win no longer counts).
        // For recurring, deal_amount is the monthly price; total = amount × term.
        $wonValue = 0.0;
        $wonRecurring = 0.0;
        $wonOneOff = 0.0;
        $mrrWon = 0.0;
        $wonDeals = DB::table('leads')
            ->where('shop_id', $shopId)
            ->where('status', 'won')
            ->whereNotNull('deal_won_at')
            ->whereBetween('deal_won_at', [$from, $to])
            ->get(['deal_amount', 'deal_type', 'deal_term_months']);
        foreach ($wonDeals as $d) {
            $amount = (float) ($d->deal_amount ?? 0);
            if ($amount <= 0) {
                continue; // won without a captured amount
            }
            if ($d->deal_type === 'recurring') {
                $total = $amount * (int) ($d->deal_term_months ?? 0);
                $wonValue += $total;
                $wonRecurring += $total;
                $mrrWon += $amount;
            } else {
                $wonValue += $amount;
                $wonOneOff += $amount;
            }
        }
```

Then add these keys to the returned array (after `'won' => $moved['won'],`):

```php
            'won_value'            => round($wonValue, 2),
            'won_value_recurring'  => round($wonRecurring, 2),
            'won_value_one_off'    => round($wonOneOff, 2),
            'mrr_won'              => round($mrrWon, 2),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ReportsAggregatorHuntTest`
Expected: PASS (existing + new case).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/ReportsAggregator.php tests/Feature/ReportsAggregatorHuntTest.php
git commit -m "feat(reports): won_value / recurring split / mrr_won in huntSummary"
```

---

### Task 5: Surface won value in the nightly AI summary

**Files:**
- Modify: `app/Services/Reports/AiInsightsWriter.php:244-262` (hunt payload) and `:330` (prompt line)

**Interfaces:**
- Consumes: `huntSummary` keys `won_value`, `mrr_won` (Task 4).
- Produces: the AI summary payload's `hunt.current` and `hunt.previous` include `won_value` and `mrr_won`; the system prompt explains them so the narrative can mention earnings.

- [ ] **Step 1: Add the fields to the current payload**

In `app/Services/Reports/AiInsightsWriter.php`, in the `hunt.current` array (after `'won' => $hunt['won'],`), add:

```php
                    'won_value'    => $hunt['won_value'],
                    'mrr_won'      => $hunt['mrr_won'],
```

- [ ] **Step 2: Add the fields to the previous payload**

In the `hunt.previous` array (after `'won' => $prevHunt['won'],`), add:

```php
                    'won_value'    => $prevHunt['won_value'],
                    'mrr_won'      => $prevHunt['mrr_won'],
```

- [ ] **Step 3: Explain the fields in the system prompt**

In the prompt bullet that describes the hunt fields (line ~330), append to the sentence:

```
 "won_value" = total AED value of deals won this period (one-off amount, or monthly price × contract months for recurring); "mrr_won" = monthly recurring AED added from recurring deals won this period.
```

- [ ] **Step 4: Verify the payload shape**

Run: `php artisan test --filter=ReportsAggregatorHuntTest` (confirms the keys the writer now reads exist)
Expected: PASS. (The writer itself has no dedicated unit test; the keys are guaranteed by Task 4.)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/AiInsightsWriter.php
git commit -m "feat(ai-summary): include won_value + mrr_won in hunt narrative"
```

---

### Task 6: Return a won-value total from the Leads index

**Files:**
- Modify: `app/Http/Controllers/LeadController.php:386-442` (`index`)
- Test: `tests/Feature/LeadDealValueTest.php` (add an index case)

**Interfaces:**
- Consumes: `deal_total` accessor (Task 2).
- Produces: `GET /shop/leads` response gains `won_value` (float) — the lifetime sum of `deal_total` for the shop's leads currently in `won`. Shown as a headline on the Leads page (Task 9).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/LeadDealValueTest.php`:

```php
    public function test_leads_index_returns_current_won_value_total(): void
    {
        [$shop, $token] = $this->actingShop();
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'W1', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'deal_won_at' => now(),
        ]);
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'W2', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now(),
        ]);
        // A passed deal must not count.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'P', 'status' => 'pass',
            'deal_amount' => 1000, 'deal_type' => 'one_off',
        ]);

        $this->auth($token)->getJson('/api/shop/leads')
            ->assertOk()
            ->assertJsonPath('won_value', 2300);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_leads_index_returns_current_won_value_total`
Expected: FAIL — `won_value` key missing from index response.

- [ ] **Step 3: Compute and return `won_value` in `index`**

In `app/Http/Controllers/LeadController.php`, in `index`, after the `$pipelines = ...` block and before the `return response()->json([`, add:

```php
        // Lifetime value of deals currently held as won (reversed deals excluded
        // because their status is no longer 'won'). Summed via the derived
        // deal_total so it stays consistent with the model.
        $wonValue = Lead::forShop($shop->id)
            ->where('status', 'won')
            ->whereNotNull('deal_amount')
            ->get(['deal_amount', 'deal_type', 'deal_term_months'])
            ->sum(fn (Lead $l) => $l->deal_total ?? 0);
```

Add `'won_value' => round((float) $wonValue, 2),` to the returned array (after `'pipelines' => $pipelines,`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_leads_index_returns_current_won_value_total`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LeadController.php tests/Feature/LeadDealValueTest.php
git commit -m "feat(leads): return current won_value total from index"
```

---

### Task 7: Frontend types + API client for the deal

**Files:**
- Modify: `admin/src/types.ts:243-275` (`Lead`, `LeadListResponse`)
- Modify: `admin/src/lib/leads.ts:159-162` (`updateLeadStatus`), `:184-195` (`listLeads`)

**Interfaces:**
- Produces:
  - `DealType = 'one_off' | 'recurring'`; `DealInput = { deal_amount: number; deal_type: DealType; deal_term_months?: number }`.
  - `Lead` type gains `deal_amount?`, `deal_type?`, `deal_term_months?`, `deal_won_at?`, `deal_total?` (all nullable).
  - `updateLeadStatus(id, status, note?, deal?)` sends the deal fields.
  - `listLeads()` returns `won_value: number`.

- [ ] **Step 1: Extend the `Lead` type**

In `admin/src/types.ts`, add to the `Lead` type (after `next_followup_at`):

```ts
  deal_amount?: number | null;
  deal_type?: 'one_off' | 'recurring' | null;
  deal_term_months?: number | null;
  deal_won_at?: string | null;
  deal_total?: number | null;
```

Add exported helpers near `LeadStatus`:

```ts
export type DealType = 'one_off' | 'recurring';
export const DEAL_TERMS = [1, 3, 6, 12] as const;
export type DealInput = { deal_amount: number; deal_type: DealType; deal_term_months?: number };
```

- [ ] **Step 2: Add `won_value` to the list response type**

In `admin/src/types.ts`, in `LeadListResponse` (line ~273), add:

```ts
  won_value?: number;
```

- [ ] **Step 3: Extend `updateLeadStatus` and `listLeads`**

In `admin/src/lib/leads.ts`, replace `updateLeadStatus`:

```ts
/** Move a lead through the funnel; on a win, optionally capture the deal value. */
export async function updateLeadStatus(
  id: number,
  status: LeadStatus,
  note?: string,
  deal?: DealInput,
): Promise<Lead> {
  const { data } = await api.patch(`/shop/leads/${id}/status`, { status, note, ...(deal ?? {}) });
  return data?.data ?? data;
}
```

Add `DealInput` to the import from `@/types` at the top of the file. In `listLeads`, include `won_value` in the returned object:

```ts
    won_value: data?.won_value ?? 0,
```

- [ ] **Step 4: Typecheck**

Run: `cd admin && npm run build` (or `npx tsc --noEmit`)
Expected: no type errors.

- [ ] **Step 5: Commit**

```bash
git add admin/src/types.ts admin/src/lib/leads.ts
git commit -m "feat(admin): deal types + deal-aware updateLeadStatus + won_value in list"
```

---

### Task 8: Won-deal capture panel in LeadDetail

**Files:**
- Modify: `admin/src/pages/LeadDetail.tsx:140-151` (`setStatus`) + render a small modal
- Test: `admin/src/pages/LeadDetail.test.tsx` (add a capture case)

**Interfaces:**
- Consumes: `updateLeadStatus(id, status, note?, deal?)`, `DealInput`, `DEAL_TERMS` (Task 7).
- Produces: choosing `won` opens a panel asking for amount + one-off/recurring (+ term); submitting calls `updateLeadStatus(lead.id, 'won', undefined, deal)`. A "Skip" path wins the lead with no deal.

- [ ] **Step 1: Write the failing test**

Add to `admin/src/pages/LeadDetail.test.tsx` (follow the file's existing render/mrepatterns; mock `updateLeadStatus`):

```tsx
it('captures a recurring deal when marking a lead won', async () => {
  const spy = vi.spyOn(leadsApi, 'updateLeadStatus').mockResolvedValue({} as never);
  // render LeadDetail with a lead in 'demo' (reuse the file's existing helper)
  renderLeadDetail({ status: 'demo' });

  fireEvent.click(await screen.findByRole('button', { name: /won/i }));
  fireEvent.change(await screen.findByLabelText(/amount/i), { target: { value: '300' } });
  fireEvent.click(screen.getByRole('button', { name: /recurring/i }));
  fireEvent.click(screen.getByRole('button', { name: /6 months/i }));
  fireEvent.click(screen.getByRole('button', { name: /save|confirm/i }));

  await waitFor(() =>
    expect(spy).toHaveBeenCalledWith(expect.any(Number), 'won', undefined, {
      deal_amount: 300, deal_type: 'recurring', deal_term_months: 6,
    }),
  );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npm test -- LeadDetail`
Expected: FAIL — no amount field appears; `updateLeadStatus` called without a deal.

- [ ] **Step 3: Implement the capture panel**

In `admin/src/pages/LeadDetail.tsx`:

Add state near the other `useState` hooks:

```tsx
const [wonModal, setWonModal] = useState(false);
const [dealAmount, setDealAmount] = useState('');
const [dealType, setDealType] = useState<'one_off' | 'recurring'>('one_off');
const [dealTerm, setDealTerm] = useState<number>(6);
```

Change `setStatus` so a win opens the modal instead of committing immediately:

```tsx
const setStatus = async (status: LeadStatus) => {
  if (!lead || status === lead.status || busy) return;
  if (status === 'won') { setWonModal(true); return; }
  await commitStatus(status);
};

const commitStatus = async (status: LeadStatus, deal?: DealInput) => {
  if (!lead) return;
  setBusy(true); setError('');
  try {
    await updateLeadStatus(lead.id, status, undefined, deal);
    await load();
  } catch {
    setError('Could not update status.');
  } finally {
    setBusy(false);
  }
};
```

Add a submit handler + a modal (styled with the page's existing card/glass classes). The modal has: an **Amount** number input (`aria-label="Amount"`), a **One-off / Recurring** toggle (two buttons), a term chip row `DEAL_TERMS.map(...)` shown only when `recurring`, a **Save** button, and a **Skip** button:

```tsx
const saveWon = async () => {
  const amount = parseFloat(dealAmount);
  const deal = Number.isFinite(amount) && amount > 0
    ? { deal_amount: amount, deal_type: dealType, ...(dealType === 'recurring' ? { deal_term_months: dealTerm } : {}) }
    : undefined;
  setWonModal(false);
  await commitStatus('won', deal);
};
```

(Render the modal conditionally on `wonModal`; wire the buttons to `setDealType`, `setDealTerm`, `saveWon`, and a Skip that calls `saveWon` with an empty amount. Import `DealInput` and `DEAL_TERMS` from `@/types`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd admin && npm test -- LeadDetail`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/LeadDetail.tsx admin/src/pages/LeadDetail.test.tsx
git commit -m "feat(admin): capture deal value when marking a lead won"
```

---

### Task 9: Show won-value headline on the Leads page

**Files:**
- Modify: `admin/src/pages/Leads.tsx:68-97` (funnel header)

**Interfaces:**
- Consumes: `listLeads()` returning `won_value` (Task 7).
- Produces: a "Won" money chip next to the Pipeline count in the Leads header, formatted as AED.

- [ ] **Step 1: Store `won_value` from the list load**

In `admin/src/pages/Leads.tsx`, add state and capture it where `setFunnel(r.funnel)` is called (line ~80):

```tsx
const [wonValue, setWonValue] = useState(0);
// ...
listLeads().then((r) => { if (alive) { setFunnel(r.funnel); setWonValue(r.won_value ?? 0); } }).catch(() => {});
```

- [ ] **Step 2: Render the chip**

Next to the existing Pipeline badge (line ~97), add:

```tsx
{wonValue > 0 && (
  <span className="lf-seg">
    Won <span className="lf-seg-count">AED {wonValue.toLocaleString('en-AE')}</span>
  </span>
)}
```

(Match the exact markup/classes of the neighbouring Pipeline badge.)

- [ ] **Step 3: Typecheck / build**

Run: `cd admin && npm run build`
Expected: no type errors; the chip renders when there is won revenue.

- [ ] **Step 4: Commit**

```bash
git add admin/src/pages/Leads.tsx
git commit -m "feat(admin): show total won value on the Leads page"
```

---

## Self-Review notes

- **Spec coverage:** data model → Task 1–2; capture UX → Task 3 (backend) + Task 8 (frontend); report surfacing (`won_value`/split/`mrr_won`) → Task 4, fed to AI summary in Task 5; visible dashboard number → Task 6 + Task 9; reversed-deal exclusion → Task 4 & 6 (status = 'won' filter) with explicit tests; period attribution by `deal_won_at` → Task 4 test; optional capture → Task 3 test `test_can_win_without_an_amount`.
- **Out-of-scope items** (multiple deals, weighted pipeline, close dates, history, churn, multi-currency) are not built by any task.
- **Type consistency:** `DealInput` shape (`deal_amount`, `deal_type`, `deal_term_months`) is identical across Tasks 7–9 and the backend validation in Task 3; `deal_total` accessor name consistent Tasks 2/6.
