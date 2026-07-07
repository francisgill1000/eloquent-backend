# Deposits, No-Show Protection & Cancellation Policy — Design + Plan (SPEC ONLY)

**Tier 1, Feature 3** of the Bookings roadmap.

> ⚠️ **SPEC ONLY — NOT IMPLEMENTED.** This feature necessarily involves taking money
> (a deposit charge via Ziina) and is therefore out of scope for the autonomous build,
> which is forbidden from writing any real money/payment/charge/Ziina-transaction code.
> This document is the design + implementation plan for Francis to build (or greenlight)
> when he chooses. The **non-payment** parts (cancellation-window policy fields, no-show
> status, policy display) *could* be built separately — see "Safe-to-build subset".

## Problem
No-shows are the #1 revenue killer for UAE salons/clinics. Today the app only invoices
*after* service (post-completion `BookingInvoice` + optional Ziina pay link). There is no
deposit-to-confirm and no cancellation policy, so a no-show costs the shop the whole slot.

## Goal
Let a shop optionally require a percentage (or fixed) deposit at booking time to confirm the
slot, define a cancellation window, and handle no-shows — turning "a booking tool" into "a
tool that protects revenue."

## Data model (proposed)
`shops` (new columns):
- `deposit_enabled` (bool, default false)
- `deposit_type` (enum `percent|fixed`, default `percent`)
- `deposit_value` (decimal — e.g. 20 = 20% or AED 20)
- `cancellation_window_hours` (int, default 24) — free cancellation cutoff
- `deposit_policy_text` (nullable) — tenant-authored copy shown at checkout
- `no_show_fee_forfeits_deposit` (bool, default true)

`bookings` (new columns):
- `deposit_required` (bool) — snapshot of policy at booking time
- `deposit_amount` (decimal, nullable)
- `deposit_status` (enum `none|pending|paid|forfeited|refunded`, default `none`)
- `deposit_invoice_id` (nullable → a BookingInvoice row of a new `kind=deposit`)
- `cancellation_deadline` (datetime, nullable) — computed = appointment − window
- add `no_show` to the booking status set (extends existing booked/completed/cancelled/queued)

Reuse `BookingInvoice` (add `kind` = `deposit|final`) rather than a new payments table, so
the existing Ziina webhook + pay-link plumbing carries the deposit too.

## Flow
1. **Booking (deposit on):** `BookingCreator` snapshots the policy, computes `deposit_amount`
   and `cancellation_deadline`, creates a `kind=deposit` BookingInvoice in `issued`, and returns
   a Ziina pay link. Slot is held as `pending_deposit` (a soft-hold) until paid, then `booked`.
   *(Ziina intent creation = the payment code that must be written by Francis.)*
2. **Deposit paid (Ziina webhook):** existing `ZiinaWebhookController` marks the deposit invoice
   `paid`, flips the booking to `booked`, and (reusing the reminders/reviews path) confirms via
   WhatsApp. **← extends existing webhook; still payment-adjacent.**
3. **Cancellation:** before `cancellation_deadline` → deposit refunded/creditable (owner policy);
   after → deposit forfeited (`deposit_status=forfeited`), slot freed.
4. **No-show:** owner marks `no_show`; if `no_show_fee_forfeits_deposit`, deposit is forfeited
   and counts as revenue-protected in analytics.

## Safe-to-build subset (NO payment code — could be built now)
These carry no charge and could be implemented under the normal TDD+staging flow:
- The **policy fields** on `shops` (cancellation window, policy text) + owner settings UI.
- `cancellation_deadline` computation + a **read-only policy display** at checkout ("Free
  cancellation up to 24h before").
- A **`no_show` booking status** + its status-service side effects (free the slot, surface in
  analytics as a no-show), decoupled from any deposit forfeiture.
- No-show **rate analytics** (already partly covered by the analytics feature).
Deferring only the *money movement* (deposit charge, forfeiture accounting, refunds) to Francis.

## Why deferred
Every revenue-protecting half of this feature is a Ziina charge/refund. Per the build's hard
rule, no real payment code is written autonomously. The schema + flow above are ready so the
payment layer is a well-bounded addition.

## Tests (when built)
- Policy snapshot on booking; deadline computation; forfeiture on late cancel; no-show frees slot;
  deposit invoice lifecycle via a **faked** Ziina (never a live charge in tests).
