# Per-Service Duration + Buffer Time — Design

**Tier 2, Feature 2** of the Bookings roadmap. Built 2026-07-08 (autonomous overnight build).

## Problem
Slot length is global today (`shop_working_hours.slot_duration`, e.g. 30 min). Every service —
a 30-min haircut and a 2-hour hair colour — occupies the same single slot, so the calendar's
`end_time` is wrong for anything longer than one slot. Services (`catalogs`) carry no duration.

## Scope (vertical slice, no payment)
- Add `duration_minutes` (nullable) + `buffer_minutes` (default 0) to `catalogs` (services).
- A `BookingDurationService` computes a booking's total minutes from its selected services.
- `BookingCreator` sets `end_time = start + computed duration` instead of one global slot.
- `CatalogController` accepts + persists the two fields so owners can set them.

## Decisions (defaults, documented)
- **Backward compatible:** if *none* of a booking's selected services has a `duration_minutes`
  set, the total falls back to the shop's existing `slot_duration` — exactly today's behaviour.
  Shops that never configure durations see no change.
- **Accurate mode:** once *any* selected service has a duration, the total is
  `Σ (duration_minutes ?? slot_duration) + buffer_minutes` across all selected services.
  A service without its own duration still occupies one fallback slot (so mixed selections don't
  under-book). Buffer is post-service cleanup/turnaround time that keeps the slot occupied.
- **Service resolution:** the booking `services` JSON array may contain catalog `id`s. Durations
  are looked up from `catalogs` scoped to the shop by those ids; entries without a resolvable id
  contribute one fallback slot. Tenant-safe (never reads another shop's catalog).
- **Buffer default 0**, duration nullable — additive, non-breaking migration.
- Slot *availability* generation (`Shop::getSlots`) is left as-is in v1: it still lists
  fixed-interval start times. Accurate `end_time` is the higher-value first step; making
  availability subtract multi-slot bookings is a follow-up (noted in Tier 3 calendar work).

## Tests (TDD)
1. No durations set → duration = shop slot_duration (legacy end_time unchanged).
2. One service with duration 90 + buffer 15 → 105 min; `end_time` reflects it.
3. Two services with durations → summed.
4. Catalog create/update persists `duration_minutes` + `buffer_minutes`.
5. Duration lookup is tenant-scoped (another shop's catalog id is ignored → fallback).
