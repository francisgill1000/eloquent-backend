# Ask Paywall / Subscription — Design Spec

**Date:** 2026-07-05
**Status:** Approved for planning
**Author:** Francis + Claude

## 1. Overview

Booking Manager and the "Ask" voice assistant are today live, ungated, and free. This
feature puts the **entire product behind a paid subscription**, sold as a single bundled
plan (the app + Ask together — never sold apart, because the voice AI is what makes the
product worth paying for).

Billing runs on the **existing Ziina one-off payment flow** as renewable time-limited
passes. Ziina has no recurring/mandate/tokenization capability (verified against
`docs.ziina.com` — its API is one-time payment intents only), so true auto-recurring is
explicitly **out of scope** for v1 and deferred to a future gateway migration (Tap
Payments / Checkout.com / Stripe).

## 2. Pricing model (locked)

| Item | Value |
| --- | --- |
| Product | Booking Manager + Ask, bundled, one plan |
| Monthly | **AED 149** → grants a **30-day** access pass |
| Annual | **AED 1,000** → grants a **365-day** access pass |
| Free trial | **30 days** for every newly created shop |
| Renewal | Manual re-payment (one-off Ziina intent) + reminders before expiry |
| On non-payment | App locks until a new pass is purchased |
| Master account | Exempt — never gated, never billed |

Prices are **editable by the master** from the `/master` area and stored in the database
(no redeploy needed to change them).

## 3. Goals

- A shop's access to the app is governed by a single, authoritative expiry timestamp.
- New shops get a 30-day trial automatically.
- Shops pay via Ziina for a 30-day or 365-day pass; payment extends their access.
- When access lapses, the app is blocked behind a `/subscribe` screen until they pay.
- Master can edit prices, view every shop's subscription status, and manually grant/extend
  access (comp a shop, fix a payment issue).
- Shops are nudged toward renewal **in-app** (trial/expiry banner + `/subscribe` screen) —
  no dependency on WhatsApp or other outbound messaging in v1.

## 4. Non-goals (explicitly out of v1)

- **Auto-recurring / card-on-file** — Ziina can't; deferred to a gateway migration.
- **Price-lock / grandfathering** — starting fresh with zero paying users, so renewals
  simply charge the current global price. The data model leaves room to add it later.
- **Multiple tiers** (e.g. "Ask Unlimited") — one plan only for now.
- **Proration / mid-period plan switching** — a new payment always extends `access_until`.
- **Per-seat / per-user billing** — subscription is per shop.
- **Outbound reminders (WhatsApp / email / SMS)** — v1 nudges in-app only; no external
  messaging dependency.

## 5. Data model

### 5.1 `subscriptions` (one row per shop)

Created lazily (on shop creation) — 1:1 with `shops`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint pk | |
| `shop_id` | bigint, unique, FK→shops cascadeOnDelete | |
| `status` | string(16) | `trialing` \| `active` \| `expired` |
| `plan` | string(16), nullable | `monthly` \| `annual` \| null (during trial) |
| `trial_ends_at` | timestamp, nullable | set at creation to now+30d |
| `access_until` | timestamp, nullable | **the authoritative access expiry** |
| `timestamps` | | |

**Access rule (single source of truth):**
`hasAccess = access_until !== null && now < access_until`.
`status` is a derived convenience label kept in sync on writes; `access_until` is what the
gate actually checks. During trial, `access_until == trial_ends_at`.

### 5.2 `subscription_payments` (audit log, one row per Ziina intent)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint pk | |
| `shop_id` | bigint, FK→shops cascadeOnDelete, indexed | |
| `plan` | string(16) | `monthly` \| `annual` |
| `amount_fils` | integer | price at time of purchase |
| `ziina_intent_id` | string, indexed | matched by the webhook |
| `ziina_operation_id` | uuid | idempotency for intent creation |
| `status` | string(16) | `pending` \| `paid` \| `failed` |
| `period_days` | integer | 30 or 365 |
| `paid_at` | timestamp, nullable | |
| `timestamps` | | |

Mirrors the existing `booking_invoices` Ziina pattern (`ziina_intent_id` /
`ziina_operation_id`), so the webhook logic is symmetric.

### 5.3 `pricing` (global, master-editable)

Singleton-style key/value, one row per plan key.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint pk | |
| `plan` | string(16), unique | `monthly` \| `annual` |
| `price_fils` | integer | e.g. 14900, 100000 |
| `timestamps` | | |

Seeded with `monthly=14900`, `annual=100000`. Master edits `price_fils`.

## 6. Backend

### 6.1 Trial initialization

When a shop is created (`ShopController@store`, the endpoint the master "Add Business"
form calls via `POST /shops`), create its `subscriptions` row:
`status=trialing`, `trial_ends_at = now+30d`, `access_until = now+30d`, `plan=null`.

The **master shop is never gated** regardless of its subscription row (see 6.4), so it
doesn't matter whether it has one.

### 6.2 `Subscription` model + service

- `Subscription` model with `hasAccess()`, `isTrialing()`, and an
  `extend(string $plan, int $days)` helper that sets `access_until = max(access_until, now) + days`,
  `status=active`, `plan=$plan`. Extending from `max(access_until, now)` means paying early
  stacks remaining time rather than wasting it.
- A `SubscriptionService` centralizes: `startTrial(Shop)`, `currentPrice(string $plan): int`
  (reads `pricing`), and `applyPaidPayment(SubscriptionPayment)` (called by the webhook).

### 6.3 Ziina service generalization

`app/Services/Ziina.php` currently hard-codes `BookingInvoice` in
`paymentLinkForBooking()`. Extract the reusable core and add:

```
createSubscriptionIntent(Shop $shop, string $plan, int $amountFils, array $urls): array
```

which builds a payment intent (amount in fils, AED, `test` flag, stable
`ziina_operation_id`, success/cancel/failure URLs) exactly like the booking path, and
returns `id` / `redirect_url` / `status`. The existing `createIntent(BookingInvoice, …)`
stays; both call a shared private `postIntent(int $amountFils, string $operationId, array $urls)`.

### 6.4 Gating middleware

New middleware `EnsureSubscribed` (alias `subscription.active`):

- If `request->user()` is a Shop and `is_master` → **pass through** (master exempt).
- Else load the shop's subscription; if `hasAccess()` → pass; otherwise return
  **HTTP 402** with `{ error: 'subscription_required', status, access_until }`.

Applied to the authenticated shop-facing route groups in `routes/api.php` — the
booking/catalog/staff/hours/customer/assistant endpoints, i.e. everything behind
`auth:sanctum` **except** the always-open set below.

**Always open (never gated):** login/auth, `GET /shop/subscription` (status),
`POST /shop/subscription/checkout` (start payment), `POST /ziina/webhook`, and all
`/master/*` routes (already master-guarded).

### 6.5 Subscription endpoints (new controller `SubscriptionController`)

- `GET /shop/subscription` → `{ status, plan, access_until, trial_ends_at, days_left, prices: { monthly, annual } }`. Used by the frontend gate + banner + `/subscribe` screen.
- `POST /shop/subscription/checkout` `{ plan: monthly|annual }` → creates a
  `subscription_payments` row (`pending`) + a Ziina intent via `createSubscriptionIntent`,
  returns `{ redirect_url, intent_id }`. Frontend redirects the browser to Ziina.

### 6.6 Webhook

Extend `ZiinaWebhookController@handlePaymentIntent`: on `status === 'completed'`, after the
existing `BookingInvoice` lookup, also try matching a `subscription_payments` row by
`ziina_intent_id`. If found and not already `paid`: mark it `paid`, set `paid_at`, and call
`SubscriptionService::applyPaidPayment()` which extends the shop's `access_until` by
`period_days` and sets `status=active`, `plan`. Idempotent (guard on current status).

### 6.7 Reminders (in-app only, v1)

**No outbound messaging in v1** — do not depend on WhatsApp/push/email. Nudging happens
entirely inside the app: the `GET /shop/subscription` response returns `days_left`, and the
frontend surfaces it as a trial/expiry banner (§7.3) that escalates as expiry nears, plus
the `/subscribe` screen itself. This means the reminder "just works" whenever the shop opens
the app, with zero external dependency. Outbound reminders (WhatsApp/email/SMS) are a
deferred add-on, not part of this build.

### 6.8 Master endpoints

- `GET /master/pricing` → current monthly/annual prices.
- `PATCH /master/pricing` `{ monthly_fils, annual_fils }` → update `pricing`.
- `PATCH /master/shops/{shop}/subscription` `{ grant_days? , status? }` → manually
  extend/reset a shop's access (comp / fix). Master-guarded.
- Extend `MasterController::presentShop()` to include the shop's `status`, `plan`,
  `access_until`, and `days_left` so the master list shows subscription state per shop.

## 7. Frontend (admin SPA)

### 7.1 Subscription context / gate

- On login, fetch `GET /shop/subscription` and hold it in context (extend `ShopContext`
  or a sibling `SubscriptionContext`).
- New `RequireSubscription` wrapper nested inside the existing `RequireShop` → `AppShell`
  in `admin/src/App.tsx`. If the shop is **not** master and has no access, redirect app
  routes to `/subscribe`. Master bypasses entirely.
- A `402 subscription_required` response from any API call also routes to `/subscribe`
  (axios interceptor), covering mid-session expiry.

### 7.2 `/subscribe` paywall screen

Glass-card screen (matches Dashboard style per `ui-match-glass-style`) showing the two
plans — **Monthly AED 149** and **Annual AED 1,000** ("save AED ~788 / ~44% off") — each
with a button that calls `POST /shop/subscription/checkout` and redirects the browser to
the returned Ziina `redirect_url`. On return (`?pay=success|cancel|failed`), re-fetch
subscription status and, on success, drop the user back into the app.

### 7.3 Trial banner

While `status=trialing`, show a slim banner in `AppShell`: "X days left in your free trial
— Subscribe" linking to `/subscribe`. The banner escalates as `days_left` shrinks (calmer
early in the trial, more prominent in the final few days). Hidden when `active`. This banner
is the v1 reminder mechanism — no outbound messaging.

### 7.4 Master pricing + status UI

- In the `/master` area, a small "Pricing" section (glass card) to edit monthly/annual
  prices (`PATCH /master/pricing`).
- The business cards / detail show each shop's subscription status + days left, and a
  "Grant 30/365 days" action (`PATCH /master/shops/{id}/subscription`).

## 8. Payment flow (sequence)

1. Shop's trial lapses (or they subscribe early) → app routes to `/subscribe`.
2. Shop picks Monthly/Annual → `POST /shop/subscription/checkout`.
3. Backend creates `subscription_payments(pending)` + Ziina intent → returns `redirect_url`.
4. Browser redirects to Ziina hosted page; shop pays.
5. Ziina → `POST /ziina/webhook` (`payment_intent.completed`).
6. Webhook marks payment `paid`, extends `access_until` by 30/365 days, `status=active`.
7. Shop returns to `/subscribe?pay=success` → frontend re-fetches status → app unlocked.

## 9. Edge cases

- **Webhook before return / return before webhook:** access is granted by the webhook, not
  the return URL. The return screen re-fetches status; if the webhook hasn't landed yet, it
  shows "confirming payment…" and polls a few times.
- **Double payment / replay:** `applyPaidPayment` is idempotent per `subscription_payments`
  row; extending from `max(access_until, now)` prevents lost time but never double-credits a
  single payment.
- **Paying while still active:** stacks time (extend from `access_until`).
- **Master with no subscription row:** exempt, always passes the gate.
- **Ziina below-minimum:** both prices (149, 1000) are well above Ziina's 2 AED minimum — no
  special handling needed.
- **Price changed mid-flight:** the `pending` payment stored `amount_fils` at checkout, so a
  price edit doesn't alter an in-progress payment.

## 10. Testing

- **Unit:** `Subscription::hasAccess/extend`, `SubscriptionService::applyPaidPayment`
  (trial→active, stacking, idempotency), `currentPrice` reads `pricing`.
- **Feature:** trial created on `POST /shops`; gated endpoint returns 402 when expired and
  200 when active; master exempt; `checkout` creates a pending payment + intent; webhook
  flips a pending payment to paid and extends access; `PATCH /master/pricing` updates price;
  master grant extends a shop.
- **Frontend:** `RequireSubscription` redirects expired non-master to `/subscribe`; master
  bypasses; `/subscribe` posts checkout and redirects; trial banner shows days left.
- Verify on the droplet (no local PHP): `php -l` + `php artisan tinker` smoke tests; phpunit
  is unavailable on the droplet, so run the assertions logically / via tinker. See memory
  `verify-backend-on-droplet`.

## 11. Rollout

1. Ship migrations (3 tables) + backend, deploy to `eloquent-backend`.
2. Seed `pricing` (monthly=14900, annual=100000).
3. Backfill: the master is the only existing shop → give it (or exempt it) and done; no
   118-shop migration (test data already wiped).
4. Deploy admin SPA (`admin/deploy.ps1`).
5. Smoke-test the full pay loop against Ziina **test** mode first (`ZIINA_TEST=true`),
   then flip to live.

## 12. Integration points (existing code)

- `app/Services/Ziina.php` — generalize intent creation (§6.3).
- `app/Http/Controllers/ZiinaWebhookController.php` — extend `handlePaymentIntent` (§6.6).
- `routes/api.php` — apply `subscription.active` to shop route groups; add subscription +
  master-pricing routes (§6.4–6.8).
- `app/Http/Controllers/ShopController.php` `@store` — start trial on creation (§6.1).
- `app/Http/Controllers/MasterController.php` — pricing + per-shop status + grant (§6.8).
- `app/Models/Shop.php` — `subscription()` relation; `is_master` already exists.
- `admin/src/App.tsx`, `admin/src/context/ShopContext.tsx` — `RequireSubscription`, context,
  `/subscribe` route (§7).
- `admin/src/pages/MasterShops.tsx` / `MasterShopDetail.tsx` — pricing + status UI (§7.4).
