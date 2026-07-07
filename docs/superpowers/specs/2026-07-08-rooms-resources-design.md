# Rooms / Resources — Design (implementation)

**Tier 3** of the Bookings roadmap. Built 2026-07-08 (continuation of the overnight build).

## Problem
Some services need a finite resource beyond staff — a treatment room, a chair, a laser machine.
Two bookings can need the same room even with different staff, so staff-only availability
under-books the calendar.

## Scope (vertical slice, no payment)
- `resources` (shop_id, name, type, is_active) — the bookable things, owner-managed.
- `catalogs.requires_resource_type` — a service declares which resource *type* it consumes.
- `bookings.resource_id` — the assignment (at most one resource per booking in v1).
- `ResourceAssigner` mirroring `StaffAssigner`: pick a free resource of the required type for a slot.
- `BookingCreator` gates `booked` on BOTH a free staff AND (no resource required OR a free
  resource). `sweep()` promotes a queued booking only when staff AND any required resource are free.

## Decisions (defaults, documented)
- **Backward compatible by default:** a service with no `requires_resource_type` needs no resource,
  so every existing booking/test path is unchanged (resource logic is a no-op unless configured).
- **One resource per booking (v1):** the required type is the first selected service that declares
  one; a free active resource of that type is assigned. Multiple simultaneous resources (pivot) is
  a later extension — one room/chair covers the dominant case.
- **Free = not held at that (date, start_time):** a resource is busy if another non-cancelled
  booking at the same shop/date/start_time already holds it. Same slot semantics as staff.
- **Queue-when-busy parity:** if staff is free but the required resource isn't, the booking is
  `queued` (staff_id + resource_id null), exactly like the no-staff case — no partial holds.
- **Sweep is FIFO + resource-aware:** promotion requires staff AND resource; if the oldest queued
  booking can't get its resource yet, sweep stops that slot (keeps ordering simple/fair).
- Tenant-scoped: resources + assignment always filtered by shop_id. No payment code.

## Endpoints
- `GET/POST /shops/{shop}/resources`, `PUT/DELETE /shops/{shop}/resources/{resource}` — owner CRUD.
- Service resource requirement rides the existing Catalog CRUD (`requires_resource_type`).

## Tests (TDD)
1. Service with no resource requirement → unchanged (booked on free staff).
2. Service requiring a room → booked when a room is free; a 2nd concurrent booking → queued (room busy).
3. Freeing the room (cancel/complete/no_show) sweeps the queued booking into it.
4. Resource assignment + requirement are tenant-scoped.
5. Resource CRUD works and is tenant-scoped.
