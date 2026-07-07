# Booking Reviews & Ratings — Design

**Tier 1, Feature 2** of the Bookings roadmap. Built 2026-07-08 (autonomous overnight build).

## Problem
The app has NO review/rating capability. After a completed booking we want to auto-ask the
customer "How was your visit? ⭐⭐⭐⭐⭐", funnel happy customers (4–5★) to a Google review link
(UAE local discovery), and keep unhappy ratings (1–3★) private for the owner to act on.

## Scope (vertical slice)
- New `booking_reviews` table (one per booking), tenant-scoped by `shop_id`.
- On booking completion, create a *pending* review-request row (guarded).
- Hourly command `reviews:send-requests` WhatsApps the customer a rating link (rides the same
  tenant-safe WhatsApp path as reminders).
- Public token endpoints so the customer can view + submit a rating with no login.
- Owner endpoint to list reviews (incl. private low ratings) + summary.

## Decisions (defaults, documented)
- **Trigger:** review request row is created inside `BookingStatusService` on completion, only
  when reviews are enabled AND the booking has a `customer_whatsapp` AND no review row exists yet.
  Creating the row (not sending) in the transition keeps the request fast + failure-isolated;
  the scheduled command does the actual WhatsApp send (idempotent via `review_request_sent_at`).
- **Funnel logic:** on submit, rating ≥ 4 returns the shop's `google_review_url` (if set) so the
  client can redirect to Google; rating ≤ 3 returns `null` → kept private for the owner. This is
  standard reputation-gating and is the whole point of the feature.
- **Token:** 48-char random, unique, unguessable — authorizes the public rate page and identifies
  the booking without exposing IDs. One review per booking (`booking_id` unique).
- **Per-shop settings** on `shops`:
  - `booking_reviews_enabled` (bool, default true) — send only happens with a WaAccount present.
  - `google_review_url` (nullable) — the shop's own Google review link; null ⇒ just thank them.
  - `review_request_template` (nullable) — tenant-neutral default with `{name} {shop} {link}`.
- **Default template:**
  `"Hi {name}, thanks for visiting {shop}! How was your experience? Tap to leave a quick rating: {link}"`
- **Link:** `{customer_url}/review/{token}` where `customer_url` is `config('app.customer_url')`.
- **Idempotency / isolation:** mirror the reminders feature — flag guards, failure caught + logged,
  each shop uses its own WaAccount + name (tenant-safe).
- **No payment code** — pure data + messaging. Compliant.

## Endpoints
- `GET  /api/reviews/{token}` (public) → `{shop_name, customer_name, rated, rating}`.
- `POST /api/reviews/{token}` (public) → body `{rating:1-5, comment?}` → records; returns
  `{google_review_url|null, message}`.
- `GET  /api/shop/reviews?shop_id=` (auth + module:bookings) → submitted reviews (incl. low) +
  `{count, average, distribution}` summary. Tenant-scoped to the authed shop.

## Tests (TDD)
1. Completing a booking with a customer WhatsApp creates one pending review (idempotent).
2. Completion without a WhatsApp / reviews disabled creates none (no regression to status svc).
3. `reviews:send-requests` sends a WA with the rating link, sets the flag, tenant-scoped; retries on failure.
4. Public GET returns context; POST with 5★ returns the Google URL; POST with 2★ returns null but stores it.
5. Owner list returns all submitted reviews incl. private low ratings, scoped to the shop, with summary.
