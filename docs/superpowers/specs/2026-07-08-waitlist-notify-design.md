# Waitlist — Notify Customer When a Slot Frees Up — Design

**Tier 3** of the Bookings roadmap. Built 2026-07-08 (autonomous overnight build).

## Problem
Bookings already get a `queued` status when no staff is free (a de-facto waitlist), and the
`StaffAssigner::sweep()` promotes them to `booked` when a staff frees up — but only the *shop* is
notified (`Notify::push`). The waiting *customer* never hears their slot was confirmed.

## Scope (vertical slice, no payment)
- When a queued booking is promoted to `booked`, WhatsApp the customer that their slot is
  confirmed — riding the same tenant-safe WhatsApp path as reminders/reviews.
- Per-shop enable + template; failure-isolated so a WhatsApp error never breaks the promotion.

## Decisions (defaults, documented)
- **Trigger point:** inside `sweep()`, right after a queued booking flips to `booked`. This is the
  one place promotion happens, so every promotion path (cancel, complete, staff re-activate,
  reassign) notifies for free.
- **Failure isolation:** the send is wrapped in try/catch; a WhatsApp failure is logged and the
  promotion still stands (the booking is already saved). Never let messaging break scheduling.
- **Gating:** only sends when the shop has `waitlist_notify_enabled` (default true) AND a WaAccount
  AND the booking has a `customer_whatsapp`. Default-on is safe (no account ⇒ no send).
- **Template** `{name} {shop} {reference} {date} {time}`, tenant-neutral default:
  `"Good news {name}! A slot opened up at {shop} and your booking {reference} is confirmed for {date} at {time}. See you then!"`
- Tenant-safe: each shop's own account + name.

## Tests (TDD)
1. Promoting a queued booking sends a WA to the customer containing its reference + shop name.
2. No send when disabled / no WaAccount / no customer_whatsapp.
3. A WhatsApp failure still leaves the booking promoted to booked (isolation).
