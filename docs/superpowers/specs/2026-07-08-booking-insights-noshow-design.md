# Booking Insights Analytics + No-Show Tracking — Design

**Tier 3** of the Bookings roadmap. Built 2026-07-08 (autonomous overnight build).

## Problem
The Reports layer already covers revenue, staff, services, and time-patterns. What's missing are
the retention/quality metrics owners actually watch: **no-show rate, cancellation rate,
repeat-customer rate, new vs returning, and average rating**. There is also no `no_show` booking
status, so no-shows are indistinguishable from cancellations today.

## Scope (vertical slice, no payment)
1. **`no_show` booking status** (the safe, non-payment subset of the deposits spec): an owner can
   mark a booking `no_show`; it frees the staff + sweeps the waitlist like a cancellation, but is
   tracked distinctly. No schema change — `status` is a free string column.
2. **Insights endpoint** `GET /api/shop/reports/insights?shop_id=&from=&to=` returning
   booking-status breakdown, the three rates, customer new/returning + repeat rate, and a review
   rating summary. Reuses existing bookings + `booking_reviews` data.

## Decisions (defaults, documented)
- **No-show frees the slot:** `no_show` joins `cancelled`/`completed` in the vacate set of
  `BookingStatusService` (staff cleared + `sweep()` promotes the waitlist) — a no-show shouldn't
  waste the slot. It does **not** touch invoices (owner decides; deposit forfeiture is the
  deferred payment feature).
- **Rate denominators:** "scheduled" = booked + completed + cancelled + no_show (excludes queued,
  which never had a confirmed slot). Each rate = its count ÷ scheduled × 100.
- **Repeat-customer rate:** among distinct customers with any booking in the range, "returning" =
  those who also have a booking dated before `from`; `repeat_rate = returning ÷ total × 100`.
  Only non-null `shop_customer_id` bookings count (walk-ins without a WhatsApp are anonymous).
- **Rating summary:** average + count of `booking_reviews` with a rating whose `rated_at` is in the
  range, scoped to the shop.
- Tenant-scoped: every query filters by `shop_id`. Assistant tool status enums are left at the
  four core statuses (no_show is an owner-UI action), avoiding churn in assistant tests.

## Tests (TDD)
1. Marking a booked booking `no_show` sets the status, frees the staff, sweeps a queued booking,
   and leaves any invoice untouched.
2. `update` endpoint accepts `no_show`.
3. Insights: given a known mix of completed/cancelled/no_show, the counts + the three rates are
   correct.
4. Insights: repeat/new-vs-returning computed correctly across a range boundary.
5. Insights: rating summary reflects `booking_reviews`; tenant-scoped (another shop excluded).
