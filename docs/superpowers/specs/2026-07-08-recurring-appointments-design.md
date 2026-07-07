# Recurring Appointments (Weekly Regulars) — Design

**Tier 3** of the Bookings roadmap. Built 2026-07-08 (autonomous overnight build).

## Problem
Many salon/clinic customers are regulars ("every Tuesday 5pm"). Today each visit is booked
one at a time. There's no way to set up a repeating appointment.

## Scope (vertical slice, no payment)
- One endpoint that creates a *series* of bookings from a base slot + a recurrence rule, reusing
  the existing `BookingCreator` (so staff auto-assignment + queue-when-busy apply per occurrence).
- Bookings in a series share a `recurring_series_id` so they can be listed/managed as a group.

## Decisions (defaults, documented)
- **Materialise, don't virtualise:** we create N concrete `bookings` rows up front rather than a
  rule evaluated lazily. This reuses every existing feature (calendar, reminders, reviews,
  invoices, staff assignment) with zero special-casing — each occurrence is just a normal booking.
- **Per-occurrence assignment:** each date goes through `BookingCreator`, so a busy occurrence is
  `queued` (waitlist) exactly like a one-off — the series doesn't force-book over conflicts.
- **Closed-day tolerance:** an occurrence whose weekday the shop is closed is skipped (caught, not
  fatal), and reported in `skipped`, so the series still creates the valid occurrences.
- **Frequencies:** `weekly` (+7d), `biweekly` (+14d), `monthly` (+1 month). **Occurrences:** an
  integer count 2–52 (bounded to avoid runaway generation). Same weekday/time is preserved for
  weekly/biweekly by construction.
- **Series id:** a random 32-char token grouping the rows; nullable on all other bookings.
- Tenant-scoped: everything created under the authed `{shop}`. No payment code.

## Endpoint
- `POST /shops/{shop}/book-recurring` — body = a normal book payload
  (`start_time, services, customer_name, customer_whatsapp, charges?, date`) plus
  `{frequency: weekly|biweekly|monthly, occurrences: 2..52}`. Returns `{series_id, created[],
  skipped[]}`.

## Tests (TDD)
1. Weekly × 4 creates 4 bookings on +0/+7/+14/+21 days, same time, one shared series_id.
2. Biweekly spacing is +14 days.
3. Each occurrence runs through BookingCreator (staff assigned when free / queued when not).
4. Validation: frequency enum + occurrences bounds.
