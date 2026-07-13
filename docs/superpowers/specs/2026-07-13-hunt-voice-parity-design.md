# Business Hunt — Voice Assistant Parity

**Date:** 2026-07-13
**Status:** Design approved, pending spec review

## Problem

An audit of the owner-assistant (voice/chat) found that when a shop runs
Business Hunt (the `leads` module), the assistant is correctly **isolated** from
bookings (module gating, prompt, and tool routing are all clean — verified by
existing tests), but Hunt **voice coverage is incomplete**. Four gaps, two
Critical:

1. **Voice "mark won" silently loses the deal value.** `HuntTools`'
   `update_lead_status` sets `status`/`last_contacted_at` only — it has no
   `deal_amount`/`deal_type`/`deal_term_months` params and never stamps
   `deal_won_at`. The web path (`LeadController::updateStatus`) captures all of
   this. So "mark Acme won, AED 150/month for 6 months" changes the status but
   throws the money away, and without `deal_won_at` the deal never appears in
   won-value reporting.
2. **No income tool.** Nothing in the assistant exposes `won_value` / `mrr_won`.
   This is the confirmed root cause of the assistant deflecting "how much have I
   earned from my leads" — a tool-gap, not a prompt refusal.
3. **No "log a follow-up" tool.** The web `LeadController::logFollowup` records a
   nudge (bumps `last_contacted_at` + logs a `contacted` activity) *without*
   changing the funnel stage. Voice has no equivalent.
4. **No "draft outreach" tool.** The web `LeadController::personalize`
   (`OutreachWriter`) AI-drafts a WhatsApp opening/follow-up per lead. Voice has
   no equivalent.

## Goal

Bring Hunt voice coverage to parity with the Hunt web UI by wiring these four
capabilities into the assistant, **reusing the web app's existing logic** so the
two surfaces cannot drift. Add **no new data model** and no new product concepts.
Still not a CRM.

## Non-goals

- No new lead data fields (deal capture already exists from the prior feature).
- No free-form date parsing (income tool uses a period enum).
- No changes to module gating / isolation (already correct).
- No booking-side changes.

## Design

### Shared logic (DRY — single source of truth for capture + totals)

**Write path — `Lead::applyWonDeal(?float $amount, ?string $type, ?int $term): void`**
A new method on the `Lead` model that both the web controller and the voice tool
call, so the won-capture rules live in exactly one place:
- When `$amount !== null`: set `deal_amount = $amount`; `deal_type = $type ?? 'one_off'`;
  `deal_term_months = ($deal_type === 'recurring') ? $term : null`.
- Always stamp `deal_won_at = deal_won_at ?? now()` (once — a re-win keeps the
  original date).
- Does NOT set `status` or save — the caller owns the transaction (both callers
  already set status + save + log an activity around it).

`LeadController::updateStatus` is refactored to call `applyWonDeal(...)` in its
`status === 'won'` branch instead of the current inline field-setting, preserving
its existing behavior and tests. Enum validation stays at each entry point (HTTP
`Rule::in`; the voice tool validates against `Lead::DEAL_TYPES`/`DEAL_TERMS`).

**Read path — `ReportsAggregator::wonValueTotals(int $shopId, ?Carbon $from, ?Carbon $to): array`**
Returns `['won_value', 'won_value_recurring', 'won_value_one_off', 'mrr_won', 'won_count']`.
- Filters `status = 'won'` (reversed/pass deals excluded).
- If `$from`/`$to` given: also `whereBetween('deal_won_at', [$from, $to])` (period).
  If null: lifetime (all currently-won leads, regardless of `deal_won_at`).
- Recurring: `amount × term` into `won_value`/`won_value_recurring`, `amount` into
  `mrr_won`. One-off: `amount` into `won_value`/`won_value_one_off`. Amount ≤ 0
  (incl. a recurring row missing its term) contributes 0 and is not counted.
- `won_count` = number of won leads that contributed a positive value.

`huntSummary` is refactored to obtain its four won-value keys from
`wonValueTotals($shopId, $from, $to)` (period), keeping its existing return shape
and tests green.

### #1 — Voice mark-won captures the deal (`update_lead_status`, HuntTools)

Extend the tool's `input_schema` with three optional params (only meaningful when
`status = 'won'`):
- `deal_amount` (number)
- `deal_type` (enum: `one_off`, `recurring`)
- `deal_term_months` (enum: `1`, `3`, `6`, `12`)

Handler behavior on `won`: validate (`deal_type` in `DEAL_TYPES`;
`deal_term_months` in `DEAL_TERMS`; require a term when `recurring`), call
`applyWonDeal(...)`, then save + log the status-change activity as today. For any
non-won status, ignore the deal params. Remains a `MutatingTool` (confirm-gated).
The confirmation **preview** includes the deal so the owner approves the money —
e.g. *"Mark {name} won — AED 150/month × 6 = AED 900 total"* (one-off →
*"AED 500 one-off"*; no amount → *"won (no deal value)"*).

Invalid input (e.g. `recurring` with no term) returns a tool error the model can
relay/ask to clarify — it does not silently win with a wrong value.

### #2 — `hunt_income` read tool (HuntReadTools)

Optional `period` enum: `lifetime` (default), `this_month`, `last_month`,
`this_week`, `last_week`, `this_year`. The handler maps the enum to a
`[from, to]` range (app timezone) — or null for `lifetime` — and calls
`wonValueTotals`. For a named period it also computes the **previous equal-length
window** (`from`/`to` shifted back by the period's length) and returns it under
`previous`, so the model can say "up/down vs last period."

Returns:
- lifetime: `{ scope: 'lifetime', won_value, won_value_recurring, won_value_one_off, mrr_won, won_count }`
- period: the same plus `range: {from, to}` and `previous: {won_value, won_value_recurring, won_value_one_off, mrr_won, won_count}`

Read-only, non-mutating (survives the mutations kill-switch), no RBAC permission
(module-gated only, like the other Hunt read tools).

### #3 — `log_followup` tool (HuntTools, mutating)

Params: `name` (required, fuzzy lead match via the `ResolvesLeads` trait).
Behavior mirrors `LeadController::logFollowup`: bump `last_contacted_at`, log a
`contacted` activity (`['channel' => 'whatsapp', 'kind' => 'followup']`), no
status change. Confirm-gated. Returns `{ logged: true, name }`. Not-found /
ambiguous name returns the standard resolver error.

### #4 — `draft_outreach` read tool (HuntReadTools)

Params: `name` (required, fuzzy match) and `kind` (enum: `opening`, `followup`;
default `opening`). Calls `OutreachWriter::personalizeForLead($shop, $lead, $kind)`
and returns `{ message, kind, name }`. Does not change data or status (mirrors
the web `personalize` endpoint), so it lives in HuntReadTools. On writer failure,
returns a graceful error message rather than throwing.

### Prompt

`AssistantPrompt::huntSection()` gains concise lines so the model reliably reaches
for the new capabilities:
- marking a lead won can capture a deal value (amount; one-off or monthly ×
  term);
- `hunt_income` answers "how much have I earned / recurring revenue" (lifetime or
  a period);
- `log_followup` records a nudge without changing the stage;
- `draft_outreach` writes an opening/follow-up message for a lead.

The existing "never state a figure without calling a tool" rule already covers the
income numbers — `hunt_income` becomes the tool it must call for earnings.

## Testing

Backend feature tests (run on the droplet sqlite harness; AI/credit paths mocked
per standing rules):
- `Lead::applyWonDeal`: one-off, recurring (sets term), recurring-without-term
  handling, `deal_won_at` stamped once (re-win preserves it), no-amount path.
- `LeadController::updateStatus` regression: existing deal-capture tests stay
  green after the refactor to `applyWonDeal`.
- `wonValueTotals`: lifetime vs period; reversed-win excluded; recurring math;
  no-amount contributes 0; tenant-scoped. `huntSummary` existing tests stay green.
- `update_lead_status` voice tool: won with recurring deal persists amount/type/
  term + stamps `deal_won_at`; recurring-without-term errors; one-off nulls term;
  won with no amount still wins; confirm-preview shows the deal.
- `hunt_income` tool: lifetime totals; a named period with `previous`; reversed
  deal excluded; tenant isolation.
- `log_followup` tool: logs a `contacted` activity, bumps `last_contacted_at`,
  leaves status unchanged; not-found name errors.
- `draft_outreach` tool: returns a message for a named lead with `OutreachWriter`
  **mocked**; writer failure returns a graceful error; not-found name errors.
- Module gating regression: a leads-only shop exposes the new Hunt tools and no
  booking tool; a bookings-only shop does not expose them (extend the existing
  registry tests).

## Files touched (anticipated)

- `app/Models/Lead.php` — `applyWonDeal()` method.
- `app/Http/Controllers/LeadController.php` — `updateStatus` uses `applyWonDeal`.
- `app/Services/Reports/ReportsAggregator.php` — `wonValueTotals()` + `huntSummary`
  refactor.
- `app/Services/Assistant/Modules/HuntTools.php` — `update_lead_status` deal
  params + capture; new `log_followup` tool.
- `app/Services/Assistant/Modules/HuntReadTools.php` — new `hunt_income` and
  `draft_outreach` tools (inject `ReportsAggregator` and `OutreachWriter`).
- `app/Support/Assistant/AssistantPrompt.php` — `huntSection()` additions.
- Tests: `tests/Feature/` (Hunt assistant tools, reports aggregator, lead deal
  value, assistant tool registry).
