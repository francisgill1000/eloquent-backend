# Intake Forms & Customer Notes — Design

**Tier 3** of the Bookings roadmap. Built 2026-07-08 (autonomous overnight build).

## Problem
Clinics need medical intake; salons need customer preferences (allergies, favourite stylist,
hair type). Today there is nowhere to record any of this. `ShopCustomer` already exists as the
per-shop customer record to build on.

## Scope (vertical slice, no payment)
- Persistent **customer notes + preferences** on `shop_customers` (seen every visit).
- Per-**booking notes** on `bookings` (this-visit intake).
- Tenant-scoped read/update endpoints; the booking-modal lookup surfaces notes + preferences so
  staff see them before the appointment.

## Decisions (defaults, documented)
- **Two levels, deliberately:** `shop_customers.notes` + `preferences` are durable (persist across
  visits — the salon's memory of the client); `bookings.notes` is per-visit (today's intake).
  Both are needed and serve different lifetimes.
- **`preferences` is free-form JSON** (`{"allergies": "...", "stylist": "Sara", ...}`) — each shop
  type structures it differently (medical vs salon), so a rigid schema would be wrong for a
  multi-tenant tool. The API validates it's an object; the shop's UI defines the keys.
- **Tenant safety:** every endpoint verifies the customer/booking belongs to the shop
  (`shop_customer.shop_id === shop.id`), returning 404 otherwise. Never cross-shop.
- **No PHI handling claims:** this stores what the owner types; it is not a regulated medical
  records system. (Worth a line in the owner UI; out of scope for the API.)
- No payment code.

## Endpoints
- `GET   /shops/{shop}/customers/{customer}` — customer detail incl. notes, preferences, and a
  small booking-history summary.
- `PATCH /shops/{shop}/customers/{customer}` — update `name?`, `notes?`, `preferences?`.
- `PATCH /booking/{id}/notes` — set a booking's `notes`.
- `lookup` response gains `notes` + `preferences` so the booking modal pre-shows them.

## Tests (TDD)
1. `show` returns the customer with notes + preferences + booking summary.
2. `PATCH customer` updates notes + structured preferences (round-trip); tenant-scoped (other
   shop's customer → 404).
3. `lookup` includes notes + preferences.
4. `PATCH booking notes` persists per-visit notes.
