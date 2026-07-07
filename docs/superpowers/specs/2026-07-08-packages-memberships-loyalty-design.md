# Packages, Memberships & Loyalty — Design + Plan (SPEC ONLY)

**Tier 2, Feature 1** of the Bookings roadmap.

> ⚠️ **SPEC ONLY — NOT IMPLEMENTED.** Prepaid packages and memberships take money (Ziina
> charges / recurring billing), which the autonomous build is forbidden from writing. This is
> the design + plan for Francis. The **loyalty-points** sub-feature and the **redemption
> ledger** are largely non-payment and have a safe-to-build subset noted below.

## Problem
Every booking today is a one-off. There is no "buy 10 sessions", no monthly membership, no
loyalty. This is the biggest lifetime-value lever — it turns transactions into a relationship.

## Three sub-features

### 1. Prepaid packages ("buy 10 sessions, use over time") — PAYMENT
Customer pays upfront for N sessions of a service; each booking decrements the balance.
- `packages` (shop_id, name, service scope, sessions_total, price, validity_days, active).
- `customer_packages` (shop_id, shop_customer_id, package_id, sessions_remaining, expires_at,
  purchase_invoice_id). Purchase = a Ziina charge → **payment code, deferred to Francis.**
- On booking, if the customer has a live package covering the service, decrement instead of
  charging. Decrement/refund-on-cancel logic is **safe to build** (no money moves at booking time).

### 2. Memberships (recurring) — PAYMENT
Monthly/annual plan granting perks (discount %, priority booking, X free sessions/month).
- Reuse the existing `subscriptions` + Ziina recurring plumbing (already built for the Ask
  paywall) rather than a new billing stack — but a *customer-facing* membership is still a
  **recurring charge = payment code, deferred.**
- `membership_plans` (shop_id, name, interval, price, perks JSON) and `customer_memberships`
  (status, current_period_end) mirror the internal subscription model.

### 3. Loyalty points — MOSTLY SAFE TO BUILD
Earn points per completed booking / per AED spent; redeem for discounts.
- `loyalty_accounts` (shop_id, shop_customer_id, points_balance).
- `loyalty_transactions` (account_id, delta, reason, booking_id) — an append-only ledger.
- Earn on booking completion (hook `BookingStatusService`, like reviews) — **no payment.**
- Redemption *as a discount* only becomes payment-adjacent when it reduces a real charge; the
  ledger + earn side is fully safe to build and test now.

## Safe-to-build subset (NO payment code)
- Loyalty accounts + ledger + earn-on-completion + balance endpoint + owner config
  (points-per-booking / points-per-AED). Tenant-scoped, mirrors the reviews hook pattern.
- Package/membership **schema + admin CRUD + balance decrement** (the money-in step is the only
  deferred part). A shop could define packages and the system could track redemptions; only the
  purchase charge waits for Francis.

## Why deferred
The revenue-capture step of packages and memberships is a Ziina transaction. Per the build's hard
rule no autonomous payment code is written. Schema + flow above make the payment layer a bounded add.

## Tests (when built)
- Loyalty earn on completion + ledger integrity; package decrement on booking + restore on cancel;
  membership perk application — all with a **faked** Ziina, never a live charge.
