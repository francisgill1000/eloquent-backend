# Business Hunt Voice Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the owner-assistant's Business Hunt (leads) voice coverage to parity with the Hunt web UI — capture deal value on voice mark-won, answer income questions, log follow-ups, and draft outreach — reusing the web app's logic so voice and web can't drift.

**Architecture:** Two shared helpers eliminate duplication: `Lead::applyWonDeal()` (write) and `ReportsAggregator::wonValueTotals()` (read); the web controller and reports refactor onto them. Then four assistant-tool changes: extend `update_lead_status` (HuntTools) to capture deals, add `hunt_income` + `draft_outreach` (HuntReadTools), add `log_followup` (HuntTools), and describe them all in the prompt. Entirely backend — no frontend changes.

**Tech Stack:** Laravel 11 (PHP 8.4), Eloquent, the app's assistant module framework (`AssistantModule`/`MutatingTool`/`ToolCall`/`ResolvesLeads`), Pest/PHPUnit feature tests with `RefreshDatabase`.

## Global Constraints

- **Reuse, don't duplicate:** deal capture goes through `Lead::applyWonDeal()`; won-value totals through `ReportsAggregator::wonValueTotals()`. Both the web (`LeadController`, `huntSummary`) and the voice tools call the same method.
- **No new data model, not a CRM.** Only wire existing capabilities into the assistant.
- **Deal semantics (unchanged):** `deal_amount` is MONTHLY for `recurring`, WHOLE for `one_off`. `deal_type ∈ {one_off, recurring}`; `deal_term_months ∈ {1,3,6,12}`, required for recurring, null for one-off. `deal_won_at` stamped once (re-win never resets). Capture optional. Currency AED.
- **Reversed-win rule:** won-value counts only leads whose CURRENT status is `won`; period figures attribute by `deal_won_at`.
- **Mutating vs read:** data-changing tools extend `MutatingTool` (confirm-gated, hidden by the `assistant.mutations_enabled` kill-switch). Read tools extend `AssistantModule`. `hunt_income` and `draft_outreach` are READ (no data change). `log_followup` and the `update_lead_status` change are MUTATING.
- **Tenant isolation:** every query scoped by `shop->id`; never from request/tool input.
- **AI/credit paths mocked in tests:** never spend a real Hunt credit or real `OutreachWriter` (Claude) call in a test — mock `LeadSearchService`/`SearchInterpreter`/`OutreachWriter` (see `HuntAssistantToolsTest`'s `fakeSearch` / Mockery pattern).
- **Backend tests run on the droplet harness (php8.4, sqlite `:memory:`), never locally** (local PHP is broken). Pass explicit test FILE PATHS, never `--filter`.

---

### Task 1: `Lead::applyWonDeal()` + refactor `LeadController::updateStatus`

**Files:**
- Modify: `app/Models/Lead.php`
- Modify: `app/Http/Controllers/LeadController.php` (`updateStatus`, ~line 328-343)
- Test: `tests/Feature/LeadDealValueTest.php`

**Interfaces:**
- Produces: `Lead::applyWonDeal(?float $amount, ?string $type = null, ?int $term = null): void` — when `$amount !== null` sets `deal_amount = $amount`, `deal_type = $type ?? 'one_off'`, `deal_term_months = ($deal_type === 'recurring') ? $term : null`; always sets `deal_won_at = deal_won_at ?? now()`. Does NOT set status or save.
- Consumes: existing `deal_*` columns and `deal_total` accessor.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/LeadDealValueTest.php`:

```php
public function test_apply_won_deal_sets_recurring_fields_and_stamps_won_at(): void
{
    $shop = Shop::factory()->create();
    $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'A', 'status' => 'demo']);

    $lead->applyWonDeal(150.0, 'recurring', 6);

    $this->assertSame(150.0, $lead->deal_amount);
    $this->assertSame('recurring', $lead->deal_type);
    $this->assertSame(6, $lead->deal_term_months);
    $this->assertSame(900.0, $lead->deal_total);
    $this->assertNotNull($lead->deal_won_at);
}

public function test_apply_won_deal_one_off_nulls_term_and_no_amount_stamps_only(): void
{
    $shop = Shop::factory()->create();
    $oneOff = Lead::create(['shop_id' => $shop->id, 'name' => 'B', 'status' => 'demo']);
    $oneOff->applyWonDeal(500.0, 'one_off', 6);
    $this->assertNull($oneOff->deal_term_months);
    $this->assertSame(500.0, $oneOff->deal_total);

    $blank = Lead::create(['shop_id' => $shop->id, 'name' => 'C', 'status' => 'demo']);
    $blank->applyWonDeal(null);
    $this->assertNull($blank->deal_amount);
    $this->assertNull($blank->deal_total);
    $this->assertNotNull($blank->deal_won_at);
}

public function test_apply_won_deal_does_not_reset_existing_won_at(): void
{
    $shop = Shop::factory()->create();
    $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'D', 'status' => 'won', 'deal_won_at' => now()->subDays(5)]);
    $original = $lead->deal_won_at->toDateTimeString();
    $lead->applyWonDeal(200.0, 'one_off');
    $this->assertSame($original, $lead->deal_won_at->toDateTimeString());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash <harness> tests/Feature/LeadDealValueTest.php`
Expected: FAIL — `applyWonDeal` undefined.

- [ ] **Step 3: Add `applyWonDeal` to `app/Models/Lead.php`**

Add near the `deal_total` accessor:

```php
    /**
     * Apply a won deal's value to this lead — shared by the web controller and
     * the voice tool so capture rules live in one place. Sets the deal fields
     * only when an amount is given; always stamps deal_won_at once (a re-win
     * keeps the original date). Does NOT set status or save — the caller owns
     * the transaction.
     */
    public function applyWonDeal(?float $amount, ?string $type = null, ?int $term = null): void
    {
        if ($amount !== null) {
            $this->deal_amount = $amount;
            $this->deal_type = $type ?? 'one_off';
            $this->deal_term_months = $this->deal_type === 'recurring' ? $term : null;
        }
        $this->deal_won_at = $this->deal_won_at ?? now();
    }
```

- [ ] **Step 4: Refactor `LeadController::updateStatus` to use it**

In `app/Http/Controllers/LeadController.php`, replace the `if ($data['status'] === 'won') { ... }` block (the inline deal-field-setting + `deal_won_at` stamp) with:

```php
        if ($data['status'] === 'won') {
            $lead->applyWonDeal(
                $data['deal_amount'] ?? null,
                $data['deal_type'] ?? null,
                $data['deal_term_months'] ?? null,
            );
        }
```

(Leave the validation rules, the `$lead->status`/`last_contacted_at` assignment, `save()`, and the activity log exactly as they are.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `bash <harness> tests/Feature/LeadDealValueTest.php`
Expected: PASS — the new `applyWonDeal` cases AND all existing `updateStatus` endpoint cases (the refactor is behavior-preserving).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Lead.php app/Http/Controllers/LeadController.php tests/Feature/LeadDealValueTest.php
git commit -m "refactor(leads): shared Lead::applyWonDeal used by web updateStatus"
```

---

### Task 2: `ReportsAggregator::wonValueTotals()` + refactor `huntSummary`

**Files:**
- Modify: `app/Services/Reports/ReportsAggregator.php`
- Test: `tests/Feature/ReportsAggregatorHuntTest.php`

**Interfaces:**
- Produces: `wonValueTotals(int $shopId, ?Carbon $from = null, ?Carbon $to = null): array` → `['won_value','won_value_recurring','won_value_one_off','mrr_won','won_count']`. Filters `status='won'`; when both dates given also `whereBetween('deal_won_at',[from,to])`; null dates → lifetime. Recurring with no/zero term contributes 0 and is not counted.
- Consumes: `deal_*` columns.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ReportsAggregatorHuntTest.php`:

```php
public function test_won_value_totals_lifetime_and_period(): void
{
    $shop = $this->shop('8010');
    // In-period recurring + one-off, a reversed (pass) deal, and an out-of-period win.
    Lead::create(['shop_id' => $shop->id, 'name' => 'R', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'deal_won_at' => now()]);
    Lead::create(['shop_id' => $shop->id, 'name' => 'O', 'status' => 'won', 'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now()]);
    Lead::create(['shop_id' => $shop->id, 'name' => 'Lost', 'status' => 'pass', 'deal_amount' => 9999, 'deal_type' => 'one_off', 'deal_won_at' => now()]);
    Lead::create(['shop_id' => $shop->id, 'name' => 'Old', 'status' => 'won', 'deal_amount' => 700, 'deal_type' => 'one_off', 'deal_won_at' => now()->subMonthsNoOverflow(2)]);

    $agg = app(ReportsAggregator::class);

    // Period = this month: excludes Old + Lost.
    $period = $agg->wonValueTotals($shop->id, now()->startOfMonth(), now()->endOfMonth());
    $this->assertSame(150.0 * 6 + 500.0, $period['won_value']);
    $this->assertSame(900.0, $period['won_value_recurring']);
    $this->assertSame(500.0, $period['won_value_one_off']);
    $this->assertSame(150.0, $period['mrr_won']);
    $this->assertSame(2, $period['won_count']);

    // Lifetime: includes Old (700) but still excludes reversed Lost.
    $life = $agg->wonValueTotals($shop->id);
    $this->assertSame(900.0 + 500.0 + 700.0, $life['won_value']);
    $this->assertSame(3, $life['won_count']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash <harness> tests/Feature/ReportsAggregatorHuntTest.php`
Expected: FAIL — `wonValueTotals` undefined.

- [ ] **Step 3: Add `wonValueTotals` to `app/Services/Reports/ReportsAggregator.php`**

```php
    /**
     * Won-deal value for a shop — lifetime when no dates are given, or a period
     * (attributed by deal_won_at) when [from,to] is passed. Only leads whose
     * CURRENT status is 'won' count (a reversed win no longer does). For
     * recurring, deal_amount is the monthly price; total = amount × term.
     */
    public function wonValueTotals(int $shopId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $q = DB::table('leads')->where('shop_id', $shopId)->where('status', 'won');
        if ($from !== null && $to !== null) {
            $q->whereNotNull('deal_won_at')->whereBetween('deal_won_at', [$from, $to]);
        }
        $rows = $q->get(['deal_amount', 'deal_type', 'deal_term_months']);

        $wonValue = 0.0; $recurring = 0.0; $oneOff = 0.0; $mrr = 0.0; $count = 0;
        foreach ($rows as $d) {
            $amount = (float) ($d->deal_amount ?? 0);
            if ($amount <= 0) {
                continue;
            }
            if ($d->deal_type === 'recurring') {
                $term = (int) ($d->deal_term_months ?? 0);
                if ($term <= 0) {
                    continue; // incomplete recurring — no computable total
                }
                $total = $amount * $term;
                $wonValue += $total; $recurring += $total; $mrr += $amount;
            } else {
                $wonValue += $amount; $oneOff += $amount;
            }
            $count++;
        }

        return [
            'won_value'           => round($wonValue, 2),
            'won_value_recurring' => round($recurring, 2),
            'won_value_one_off'   => round($oneOff, 2),
            'mrr_won'             => round($mrr, 2),
            'won_count'           => $count,
        ];
    }
```

- [ ] **Step 4: Refactor `huntSummary` to use it**

In `huntSummary`, delete the inline won-value loop (the `$wonDeals = DB::table('leads')...` block and its `foreach`) and replace the four won-value keys in the return array with values from the helper. Just before the `return [`:

```php
        $wonTotals = $this->wonValueTotals($shopId, $from, $to);
```

And in the returned array, replace the four `'won_value*'/'mrr_won'` lines with:

```php
            'won_value'            => $wonTotals['won_value'],
            'won_value_recurring'  => $wonTotals['won_value_recurring'],
            'won_value_one_off'    => $wonTotals['won_value_one_off'],
            'mrr_won'              => $wonTotals['mrr_won'],
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `bash <harness> tests/Feature/ReportsAggregatorHuntTest.php`
Expected: PASS — the new `wonValueTotals` case AND the existing `huntSummary` won-value test (identical results; its fixtures have no null-term recurring win, so the refactor changes nothing observable).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Reports/ReportsAggregator.php tests/Feature/ReportsAggregatorHuntTest.php
git commit -m "refactor(reports): shared wonValueTotals (lifetime/period) used by huntSummary"
```

---

### Task 3: Capture deal value in the `update_lead_status` voice tool

**Files:**
- Modify: `app/Services/Assistant/Modules/HuntTools.php`
- Test: `tests/Feature/HuntAssistantToolsTest.php`

**Interfaces:**
- Consumes: `Lead::applyWonDeal()` (Task 1), `Lead::DEAL_TYPES`, `Lead::DEAL_TERMS`, the `gate()`/`preview()`/`applied()` flow.
- Produces: `update_lead_status` accepts optional `deal_amount`/`deal_type`/`deal_term_months`; on `won` it captures via `applyWonDeal`, and the confirm preview names the deal.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/HuntAssistantToolsTest.php`:

```php
public function test_update_lead_status_won_captures_recurring_deal(): void
{
    $shop = $this->leadsShop();
    $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'demo']);

    $preview = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'deal_term_months' => 6]);
    $this->assertTrue($preview['preview']);
    $this->assertStringContainsString('900', $preview['action']); // 150 × 6 shown

    $done = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'confirmed' => true]);
    $this->assertTrue($done['done']);
    $fresh = $lead->fresh();
    $this->assertSame('won', $fresh->status);
    $this->assertSame(150.0, $fresh->deal_amount);
    $this->assertSame(6, $fresh->deal_term_months);
    $this->assertSame(900.0, $fresh->deal_total);
    $this->assertNotNull($fresh->deal_won_at);
}

public function test_update_lead_status_won_requires_term_for_recurring(): void
{
    $shop = $this->leadsShop();
    Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'demo']);
    $out = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'confirmed' => true]);
    $this->assertSame('missing_deal_term', $out['error']);
}

public function test_update_lead_status_won_without_amount_still_wins(): void
{
    $shop = $this->leadsShop();
    $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'demo']);
    $done = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'confirmed' => true]);
    $this->assertTrue($done['done']);
    $this->assertSame('won', $lead->fresh()->status);
    $this->assertNull($lead->fresh()->deal_amount);
    $this->assertNotNull($lead->fresh()->deal_won_at);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: FAIL — deal params ignored; no `900` in preview; no `missing_deal_term`.

- [ ] **Step 3: Rewrite `updateStatus` in `app/Services/Assistant/Modules/HuntTools.php`**

```php
    private function updateStatus(ToolCall $call): array
    {
        $new = strtolower(trim((string) $call->get('status')));
        if (! in_array($new, Lead::STATUSES, true)) {
            return ['error' => 'invalid_status'];
        }

        // Deal value is only meaningful on a win. Normalise + validate up front.
        $amount = $call->get('deal_amount');
        $amount = ($amount === null || $amount === '') ? null : (float) $amount;
        $type = $call->get('deal_type');
        $term = $call->get('deal_term_months');
        $term = ($term === null || $term === '') ? null : (int) $term;

        if ($new === 'won' && $amount !== null) {
            if ($type !== null && ! in_array($type, Lead::DEAL_TYPES, true)) {
                return ['error' => 'invalid_deal_type'];
            }
            if ($type === 'recurring') {
                if ($term === null) {
                    return ['error' => 'missing_deal_term'];
                }
                if (! in_array($term, Lead::DEAL_TERMS, true)) {
                    return ['error' => 'invalid_deal_term'];
                }
            }
        }

        return $this->gate(
            $call,
            resolve: fn () => $this->resolveLead($call),
            describe: fn ($lead) => [
                $this->describeStatusChange($lead, $new, $amount, $type, $term),
                ['status' => "{$lead->status} → {$new}"],
            ],
            write: function ($lead) use ($new, $amount, $type, $term) {
                $from = $lead->status;
                $lead->status = $new;
                $lead->last_contacted_at = now();
                if ($new === 'won') {
                    $lead->applyWonDeal($amount, $type, $term);
                }
                $lead->save();

                $lead->activities()->create([
                    'type' => LeadActivity::TYPE_STATUS_CHANGE,
                    'payload' => ['from' => $from, 'to' => $new],
                    'user_id' => current_shop_user()?->id,
                ]);

                return ['name' => $lead->name, 'status' => $new, 'deal_total' => $lead->deal_total];
            },
        );
    }

    /** Confirm-preview line; names the deal when winning with an amount. */
    private function describeStatusChange(Lead $lead, string $new, ?float $amount, ?string $type, ?int $term): string
    {
        $base = "Move {$lead->name} from {$lead->status} to {$new}";
        if ($new !== 'won' || $amount === null) {
            return $base;
        }
        if (($type ?? 'one_off') === 'recurring') {
            $total = $amount * (int) $term;
            return "{$base} — AED {$amount}/month × {$term} = AED {$total} total";
        }
        return "{$base} — AED {$amount} one-off";
    }
```

- [ ] **Step 4: Add the deal params to the `update_lead_status` tool def**

In `toolDefs()`, extend the `update_lead_status` entry's `properties` (keep `required` as `['name', 'status']`):

```php
            ['name' => 'update_lead_status', 'description' => 'Move a lead through the funnel (new, sent, followup, replied, demo, won, pass). Identify the lead by business name. When moving to "won", you may also capture the deal value: deal_amount (AED), deal_type ("one_off" or "recurring"), and for recurring a deal_term_months of 1, 3, 6, or 12 (deal_amount is the MONTHLY price for recurring). Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).'],
                'status' => ['type' => 'string', 'enum' => Lead::STATUSES],
                'deal_amount' => ['type' => 'number', 'description' => 'Deal value in AED when winning. Monthly price if recurring, whole amount if one-off.'],
                'deal_type' => ['type' => 'string', 'enum' => Lead::DEAL_TYPES],
                'deal_term_months' => ['type' => 'integer', 'enum' => Lead::DEAL_TERMS, 'description' => 'Contract length for a recurring deal.'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name', 'status']]],
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: PASS — new deal-capture cases AND the existing `update_lead_status` tests (they don't pass deal params, so behavior is unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Assistant/Modules/HuntTools.php tests/Feature/HuntAssistantToolsTest.php
git commit -m "feat(assistant): voice mark-won captures deal value via applyWonDeal"
```

---

### Task 4: `hunt_income` read tool

**Files:**
- Modify: `app/Services/Assistant/Modules/HuntReadTools.php`
- Test: `tests/Feature/HuntAssistantToolsTest.php`

**Interfaces:**
- Consumes: `ReportsAggregator::wonValueTotals()` (Task 2).
- Produces: `hunt_income` read tool with an optional `period` enum; returns totals (+ `range` and `previous` for a named period).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/HuntAssistantToolsTest.php`:

```php
public function test_hunt_income_lifetime_totals(): void
{
    $shop = $this->leadsShop();
    Lead::create(['shop_id' => $shop->id, 'name' => 'R', 'status' => 'won', 'deal_amount' => 150, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'deal_won_at' => now()]);
    Lead::create(['shop_id' => $shop->id, 'name' => 'Lost', 'status' => 'pass', 'deal_amount' => 9999, 'deal_type' => 'one_off', 'deal_won_at' => now()]);

    $out = $this->exec($shop, 'hunt_income');
    $this->assertSame('lifetime', $out['scope']);
    $this->assertSame(900.0, $out['won_value']);
    $this->assertSame(150.0, $out['mrr_won']);
    $this->assertSame(1, $out['won_count']);
}

public function test_hunt_income_period_includes_previous(): void
{
    $shop = $this->leadsShop();
    Lead::create(['shop_id' => $shop->id, 'name' => 'ThisMonth', 'status' => 'won', 'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now()]);

    $out = $this->exec($shop, 'hunt_income', ['period' => 'this_month']);
    $this->assertSame('this_month', $out['scope']);
    $this->assertSame(500.0, $out['won_value']);
    $this->assertArrayHasKey('previous', $out);
    $this->assertArrayHasKey('range', $out);
}

public function test_hunt_income_rejects_unknown_period(): void
{
    $out = $this->exec($this->leadsShop(), 'hunt_income', ['period' => 'yesterday']);
    $this->assertSame('invalid_period', $out['error']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: FAIL — `hunt_income` is `unknown_tool`.

- [ ] **Step 3: Wire the tool into `app/Services/Assistant/Modules/HuntReadTools.php`**

Add the import at the top:

```php
use App\Services\Reports\ReportsAggregator;
```

Inject the aggregator in the constructor (keep the existing params):

```php
    public function __construct(
        protected HuntCreditService $credits,
        protected AssistantActions $actions,
        protected ReportsAggregator $reports,
    ) {}
```

Add to `permissions()`: `'hunt_income' => null,`. Add to `handle()`'s match: `'hunt_income' => $this->income($call),`.

Add the handler methods:

```php
    private function income(ToolCall $call): array
    {
        $period = strtolower(trim((string) $call->get('period', 'lifetime')));
        [$from, $to] = $this->periodRange($period);
        if ($period !== 'lifetime' && $from === null) {
            return ['error' => 'invalid_period'];
        }

        $totals = $this->reports->wonValueTotals($call->shop->id, $from, $to);

        if ($from === null) {
            return array_merge(['scope' => 'lifetime'], $totals);
        }

        // Previous equal-length window immediately before [from, to].
        $len = $from->diffInSeconds($to);
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subSeconds($len);
        $previous = $this->reports->wonValueTotals($call->shop->id, $prevFrom, $prevTo);

        return array_merge(
            ['scope' => $period, 'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()]],
            $totals,
            ['previous' => $previous],
        );
    }

    /** @return array{0: ?\Carbon\Carbon, 1: ?\Carbon\Carbon} [from, to]; [null,null] for lifetime/unknown. */
    private function periodRange(string $period): array
    {
        $now = now();
        return match ($period) {
            'lifetime'   => [null, null],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_week'  => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_week'  => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'this_year'  => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default      => [null, null],
        };
    }
```

Add the tool def in `toolDefs()`:

```php
            ['name' => 'hunt_income', 'description' => 'The revenue/income the shop has won from its Business Hunt pipeline — money from deals marked won. Returns a lifetime total by default; pass a period for that period\'s won income (with the previous period for comparison). Returns won_value (total AED), won_value_recurring, won_value_one_off, mrr_won (monthly recurring AED added), and won_count.', 'input_schema' => ['type' => 'object', 'properties' => [
                'period' => ['type' => 'string', 'enum' => ['lifetime', 'this_month', 'last_month', 'this_week', 'last_week', 'this_year'], 'description' => 'Defaults to lifetime.'],
            ]]],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/Modules/HuntReadTools.php tests/Feature/HuntAssistantToolsTest.php
git commit -m "feat(assistant): hunt_income read tool (lifetime + period won revenue)"
```

---

### Task 5: `log_followup` tool

**Files:**
- Modify: `app/Services/Assistant/Modules/HuntTools.php`
- Test: `tests/Feature/HuntAssistantToolsTest.php`

**Interfaces:**
- Consumes: `ResolvesLeads`, `gate()`, `LeadActivity::TYPE_CONTACTED`.
- Produces: `log_followup` mutating tool — bumps `last_contacted_at`, logs a `contacted` activity, no status change.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/HuntAssistantToolsTest.php`:

```php
public function test_log_followup_records_contact_without_status_change(): void
{
    $shop = $this->leadsShop();
    $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'sent']);

    $preview = $this->exec($shop, 'log_followup', ['name' => 'marina']);
    $this->assertTrue($preview['preview']);
    $this->assertSame(0, $lead->activities()->count());

    $done = $this->exec($shop, 'log_followup', ['name' => 'marina', 'confirmed' => true]);
    $this->assertTrue($done['done']);
    $fresh = $lead->fresh();
    $this->assertSame('sent', $fresh->status); // unchanged
    $this->assertNotNull($fresh->last_contacted_at);
    $this->assertSame(1, $fresh->activities()->where('type', LeadActivity::TYPE_CONTACTED)->count());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: FAIL — `log_followup` is `unknown_tool`.

- [ ] **Step 3: Add the tool to `app/Services/Assistant/Modules/HuntTools.php`**

Add to `permissions()`: `'log_followup' => null,`. Add to `handle()`'s match: `'log_followup' => $this->logFollowup($call),`.

Add the handler:

```php
    private function logFollowup(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolveLead($call),
            describe: fn ($lead) => ["Log a follow-up with {$lead->name} (no status change)", ['followup' => 'logged']],
            write: function ($lead) {
                $lead->last_contacted_at = now();
                $lead->save();

                $lead->activities()->create([
                    'type' => LeadActivity::TYPE_CONTACTED,
                    'payload' => ['channel' => 'whatsapp', 'kind' => 'followup'],
                    'user_id' => current_shop_user()?->id,
                ]);

                return ['name' => $lead->name, 'logged' => true];
            },
        );
    }
```

Add the tool def in `toolDefs()`:

```php
            ['name' => 'log_followup', 'description' => 'Record that the owner followed up with a lead (a nudge) WITHOUT changing its funnel stage. Use when the owner says they messaged/called a lead again but nothing moved yet. Identify the lead by business name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/Modules/HuntTools.php tests/Feature/HuntAssistantToolsTest.php
git commit -m "feat(assistant): log_followup voice tool (nudge without stage change)"
```

---

### Task 6: `draft_outreach` read tool

**Files:**
- Modify: `app/Services/Assistant/Modules/HuntReadTools.php`
- Test: `tests/Feature/HuntAssistantToolsTest.php`

**Interfaces:**
- Consumes: `OutreachWriter::personalizeForLead(Shop, Lead, string): string`, `ResolvesLeads`.
- Produces: `draft_outreach` read tool returning `{name, kind, message}`; graceful `draft_failed` on writer error.

- [ ] **Step 1: Write the failing tests (OutreachWriter mocked — no real AI call)**

Add to `tests/Feature/HuntAssistantToolsTest.php` (the file already imports `Mockery`):

```php
public function test_draft_outreach_returns_message(): void
{
    $shop = $this->leadsShop();
    Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'new']);

    $writer = Mockery::mock(\App\Services\Leads\OutreachWriter::class);
    $writer->shouldReceive('personalizeForLead')->once()->andReturn('Hi Marina Gym, quick idea for you...');
    $this->app->instance(\App\Services\Leads\OutreachWriter::class, $writer);

    $out = $this->exec($shop, 'draft_outreach', ['name' => 'marina', 'kind' => 'opening']);
    $this->assertSame('Marina Gym', $out['name']);
    $this->assertSame('opening', $out['kind']);
    $this->assertStringContainsString('Marina Gym', $out['message']);
}

public function test_draft_outreach_handles_writer_failure(): void
{
    $shop = $this->leadsShop();
    Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'new']);

    $writer = Mockery::mock(\App\Services\Leads\OutreachWriter::class);
    $writer->shouldReceive('personalizeForLead')->andThrow(new \RuntimeException('AI down'));
    $this->app->instance(\App\Services\Leads\OutreachWriter::class, $writer);

    $out = $this->exec($shop, 'draft_outreach', ['name' => 'marina']);
    $this->assertSame('draft_failed', $out['error']);
}

public function test_draft_outreach_not_found(): void
{
    $out = $this->exec($this->leadsShop(), 'draft_outreach', ['name' => 'nobody']);
    $this->assertSame('not_found', $out['error']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: FAIL — `draft_outreach` is `unknown_tool`.

- [ ] **Step 3: Wire the tool into `app/Services/Assistant/Modules/HuntReadTools.php`**

Add the import:

```php
use App\Services\Leads\OutreachWriter;
```

Add the writer to the constructor (keep existing params incl. the `ReportsAggregator` from Task 4):

```php
    public function __construct(
        protected HuntCreditService $credits,
        protected AssistantActions $actions,
        protected ReportsAggregator $reports,
        protected OutreachWriter $writer,
    ) {}
```

Add to `permissions()`: `'draft_outreach' => null,`. Add to `handle()`'s match: `'draft_outreach' => $this->draft($call),`.

Add the handler:

```php
    private function draft(ToolCall $call): array
    {
        $lead = $this->resolveLead($call);
        if (is_array($lead)) {
            return $lead; // notFound / ambiguous
        }

        $kind = strtolower(trim((string) $call->get('kind', 'opening')));
        if (! in_array($kind, ['opening', 'followup'], true)) {
            $kind = 'opening';
        }

        try {
            $message = $this->writer->personalizeForLead($call->shop, $lead, $kind);
        } catch (\Throwable $e) {
            report($e);
            return ['error' => 'draft_failed'];
        }

        return ['name' => $lead->name, 'kind' => $kind, 'message' => $message];
    }
```

Add the tool def in `toolDefs()`:

```php
            ['name' => 'draft_outreach', 'description' => 'Write a ready-to-send WhatsApp message for a lead — an "opening" first-contact message or a "followup". Identify the lead by business name. This only drafts the text for the owner to send; it does not send anything or change the lead.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).'],
                'kind' => ['type' => 'string', 'enum' => ['opening', 'followup'], 'description' => 'Defaults to opening.'],
            ], 'required' => ['name']]],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/Modules/HuntReadTools.php tests/Feature/HuntAssistantToolsTest.php
git commit -m "feat(assistant): draft_outreach read tool (AI-draft opening/followup)"
```

---

### Task 7: Describe the new tools in the prompt + gating regression

**Files:**
- Modify: `app/Support/Assistant/AssistantPrompt.php` (`huntSection`)
- Test: `tests/Feature/HuntAssistantToolsTest.php` (registry exposure)

**Interfaces:**
- Consumes: the four tool changes above.
- Produces: `huntSection()` mentions the new capabilities; a test asserts a leads-only shop exposes all new tools and a bookings-only shop exposes none.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/HuntAssistantToolsTest.php`:

```php
public function test_leads_shop_exposes_new_hunt_tools(): void
{
    $names = array_column(app(AssistantToolRegistry::class)->defs($this->leadsShop()), 'name');
    foreach (['hunt_income', 'log_followup', 'draft_outreach'] as $t) {
        $this->assertContains($t, $names);
    }
}

public function test_bookings_only_shop_hides_new_hunt_tools(): void
{
    $shop = Shop::create(['name' => 'B2', 'shop_code' => '7102', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings']]);
    $names = array_column(app(AssistantToolRegistry::class)->defs($shop), 'name');
    foreach (['hunt_income', 'log_followup', 'draft_outreach'] as $t) {
        $this->assertNotContains($t, $names);
    }
}
```

- [ ] **Step 2: Run test to verify it passes already for gating (tools are module-gated) — then confirm prompt**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php`
Expected: PASS for exposure (the tools inherit `moduleKey() === 'leads'` from their modules, so gating is automatic). If it fails, a tool wasn't registered in `permissions()`/`toolDefs()` — fix that task's module. This test is the regression guard.

- [ ] **Step 3: Update `huntSection()` in `app/Support/Assistant/AssistantPrompt.php`**

Add these bullets inside the `huntSection()` heredoc, after the `update_lead_status` line:

```
        - When moving a lead to "won", you can capture the deal value: ask for the amount and whether it's a one-off or a monthly (recurring) deal, and for recurring the term in months (1, 3, 6, or 12). Read back the total (monthly × months) in the confirmation.
        - Tell the owner how much they've earned from the pipeline with hunt_income — a lifetime total, or a period (this month, last month, this week, last week, this year). It gives the won total, the split of one-off vs recurring, and the monthly recurring amount.
        - Record a follow-up nudge (the owner messaged/called a lead again but nothing changed yet) with log_followup — this does NOT move the funnel stage.
        - Draft a ready-to-send WhatsApp message for a lead with draft_outreach (an opening or a follow-up). It only writes the text — the owner sends it themselves.
```

- [ ] **Step 4: Run the full Hunt + reports + deal suite once**

Run: `bash <harness> tests/Feature/HuntAssistantToolsTest.php tests/Feature/ReportsAggregatorHuntTest.php tests/Feature/LeadDealValueTest.php tests/Feature/AssistantToolRegistryTest.php tests/Feature/AssistantPromptTest.php`
Expected: PASS across all — no regressions in gating, prompt, reports, or deal capture.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Assistant/AssistantPrompt.php tests/Feature/HuntAssistantToolsTest.php
git commit -m "feat(assistant): describe income/followup/outreach/deal-capture in hunt prompt"
```

---

## Self-Review notes

- **Spec coverage:** shared write helper → Task 1; shared read helper → Task 2; #1 voice deal capture → Task 3; #2 income tool → Task 4; #3 log_followup → Task 5; #4 draft_outreach → Task 6; prompt + gating → Task 7. Every spec section maps to a task.
- **DRY:** `applyWonDeal` (Task 1) is the sole capture path for both web and voice; `wonValueTotals` (Task 2) is the sole totals path for both `huntSummary` and `hunt_income`. Task 2 also folds in the earlier "null-term recurring" hardening consistently (skips such rows from all figures incl. mrr).
- **Mocking:** Task 3/5 mutate real DB rows (fine on sqlite); Task 6 mocks `OutreachWriter` (no real AI call); no task spends a real Hunt credit.
- **Type consistency:** `applyWonDeal(?float,?string,?int)` used identically in Tasks 1 and 3; `wonValueTotals(int,?Carbon,?Carbon)` returns the same 5 keys used in Tasks 2 and 4; tool names (`hunt_income`, `log_followup`, `draft_outreach`) identical across Tasks 4-7.
- **No frontend changes** — the voice UI dispatches tools generically.
