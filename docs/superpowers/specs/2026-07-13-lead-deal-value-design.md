# Lead Deal Value — capturing revenue won from the pipeline

**Date:** 2026-07-13
**Status:** Design approved, pending spec review

## Problem

When a lead moves through the Business Hunt funnel (`new → sent → followup →
replied → demo → won → pass`) and reaches `won`, **no money is captured**.
There is no monetary field anywhere on a lead. As a result the Hunt pipeline
report (`ReportsAggregator::huntSummary`) can only say *"3 leads won"* — it can
never say *"AED 12,000 won"*. The dashboard therefore has no way to show how
much business the pipeline actually produced.

## Goal

Capture the value of a deal at the moment a lead is won, and surface the total
won revenue (and its shape) in the Hunt pipeline report — with the smallest
change that closes the gap. **This is not a CRM.** No deal objects, no expected
value / weighted pipeline, no close-date forecasting, no deal history table.

## Audience constraint

Business Lens targets small UAE service businesses. Their wins are often not a
single one-off payment but a **retainer / contract** that runs for a term
(1, 3, 6, or 12 months) at a **monthly price**. The capture must model both a
one-off amount and a recurring monthly amount over a term, and nothing heavier.

## Design

### 1. Data model

One migration adds four nullable columns to `leads`:

| Column | Type | Meaning |
|---|---|---|
| `deal_amount` | `decimal(10,2)` nullable | The number the user types. For `recurring` this is the **monthly** price; for `one_off` it is the whole amount. |
| `deal_type` | `string` nullable | `'one_off'` or `'recurring'`. |
| `deal_term_months` | `unsignedSmallInteger` nullable | `null` for one-off; one of `1 / 3 / 6 / 12` for recurring. |
| `deal_won_at` | `timestamp` nullable | When the lead was marked won. The report attributes revenue to a period by this timestamp. |

All nullable — existing leads and any "won without a number" are unaffected.

**Derived, not stored** (computed accessor on the `Lead` model, so it can never
drift from its inputs):

- `deal_total` = `deal_type === 'one_off' ? deal_amount : deal_amount * deal_term_months`
  - Returns `null` when `deal_amount` is null.

Constants on the `Lead` model:
- `DEAL_TYPES = ['one_off', 'recurring']`
- `DEAL_TERMS = [1, 3, 6, 12]`

`deal_amount`, `deal_type`, `deal_term_months` added to `$fillable`;
`deal_won_at` cast to `datetime`; `deal_total` exposed via `$appends`.

### 2. Capture UX

The status change already flows through a single endpoint,
`PATCH /shop/leads/{lead}/status` (`LeadController::updateStatus`), which saves
the status and logs a `status_change` activity. Capture hooks in here.

**Frontend (LeadDetail):** when the user flips a lead to `won`, a small inline
panel appears (not a separate page/route):

- **Amount** (AED) — numeric input.
- **Structure** — a `One-off` / `Recurring` toggle. When `Recurring`, a term
  chip row appears: `1 / 3 / 6 / 12 months`.

**Optional by design:** the user may still mark a lead won without entering an
amount (the funnel must never be blocked on money). The panel nudges but does
not require. If no amount is entered, `deal_*` stay null and the win counts
toward the *count* of won but contributes `0` to won value.

**Editable later:** the amount/structure can be changed from the lead after the
fact (a deal that changed terms). Editing recomputes `deal_total` live.

**Backend (`updateStatus`) changes:**
- Accept optional `deal_amount` (numeric, min 0), `deal_type`
  (`Rule::in(Lead::DEAL_TYPES)`), `deal_term_months`
  (`Rule::in(Lead::DEAL_TERMS)`), only meaningful when `status === 'won'`.
- Validation: if `deal_type === 'recurring'` then `deal_term_months` is
  required; if `one_off` then `deal_term_months` is forced null.
- When the target status is `won`: set `deal_won_at = now()` (only if not
  already set, so re-saving a won lead does not reset its won date) and persist
  the deal fields.
- When the target status is **not** `won`: leave the deal fields as-is (keep the
  data on the row; see reversed-deal rule below). Do not touch `deal_won_at`.

### 3. Report surfacing

`ReportsAggregator::huntSummary` gains three new numbers, all attributed by
`deal_won_at` within `[from, to]` **and** restricted to leads *currently* in
`won` status (see reversed-deal rule):

- `won_value` — total contract value won in the period (headline: *"AED 12,000
  won"*). Sum of `deal_total` over qualifying leads.
- `won_value_recurring` and `won_value_one_off` — the same total split by
  `deal_type`.
- `mrr_won` — sum of `deal_amount` over qualifying **recurring** wins (monthly
  recurring revenue added: *"AED 1,800/mo added"*).

Computed in PHP over the qualifying won leads (a bounded per-shop set), matching
the existing portable-aggregation style already used in `huntSummary` for status
moves. Returned alongside the existing `won` count.

**Admin Hunt report card:** show a money headline (`won_value`) next to the
existing won count, with the recurring/one-off split and `mrr_won` as secondary
figures.

### 4. Rules & edge cases

- **Period attribution** is by `deal_won_at`, never `created_at`. A lead created
  in January but won in March counts as March revenue, and counts **once**, in
  the period it was won.
- **Reversed deals (won → later `pass`):** keep the `deal_*` data on the row,
  but **exclude it from `won_value`**. Only leads whose *current* status is `won`
  with a `deal_won_at` in range count. A deal that fell through stops counting,
  so the dashboard reflects real, held revenue. (User decision, 2026-07-13.)
- **Won without an amount:** counts toward the won *count*, contributes `0` to
  `won_value` (`deal_total` is null → treated as 0 in the sum).
- **No history table, no audit of amount changes** — that would be CRM creep.
  Editing simply overwrites.

## Out of scope (explicitly not building)

- Multiple deals per lead.
- Expected/weighted pipeline value on non-won stages.
- Close-date forecasting.
- Deal/amount change history.
- Currency other than AED.
- Recurring-revenue *churn* tracking (whether a recurring deal is still active
  after its term). `mrr_won` is "added this period", not a live MRR balance.

## Testing

- **Model:** `deal_total` computes correctly for one-off, recurring, and null
  amount; casts and appends present.
- **`updateStatus`:** won with recurring deal sets `deal_won_at` + fields;
  recurring requires term; one-off nulls the term; won without amount leaves
  fields null; re-winning does not reset `deal_won_at`; moving won→pass keeps
  the fields but the report excludes it.
- **`huntSummary`:** `won_value` / split / `mrr_won` correct across a mix of
  one-off, recurring, amount-less, and reversed (won-then-passed) leads;
  period attribution by `deal_won_at`; tenant isolation (another shop's won
  deals never leak in).

## Files touched (anticipated)

- `database/migrations/2026_07_13_*_add_deal_value_to_leads_table.php` (new)
- `app/Models/Lead.php` — fillable, casts, appends, constants, `deal_total`.
- `app/Http/Controllers/LeadController.php` — `updateStatus` validation + set.
- `app/Services/Reports/ReportsAggregator.php` — `huntSummary` new figures.
- `admin/src/pages/LeadDetail.tsx` — inline won-deal capture panel + edit.
- Admin Hunt report card component — money headline.
- Tests: `tests/Feature/` lead status + reports aggregator coverage.
