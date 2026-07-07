# Staff-Level Availability (Schedules, Breaks, Time-Off) — Design

**Tier 2, Feature 3** of the Bookings roadmap. Built 2026-07-08 (autonomous overnight build).

## Problem
Working hours are shop-wide only (`shop_working_hours`). Real salons have staff on different
shifts who take days off and breaks. Today auto-assignment (`StaffAssigner`) can hand a booking
to a staff member who isn't actually working — a correctness gap that appears the moment a shop
has more than one or two staff.

## Scope (vertical slice, no payment)
- `staff_schedules` — optional per-staff weekly shifts. A staff with *no* schedule rows inherits
  the shop's hours (backward compatible); with rows, they're only available inside their shifts.
- `staff_time_off` — day-off or partial (break/leave) unavailability windows.
- `StaffAvailabilityService::isAvailable(staff, date, startTime)` — the single source of truth.
- `StaffAssigner::pickStaffForSlot` filters candidates through it — the real payoff: an off /
  outside-shift staff member is never auto-assigned.
- Tenant-scoped CRUD nested under the existing `/shops/{shop}/staff/{staff}` routes.

## Decisions (defaults, documented)
- **Inherit-by-default:** no schedule rows ⇒ staff follows shop hours (unchanged behaviour). This
  keeps every existing shop working exactly as before; schedules are strictly opt-in per staff.
- **Schedule-constrained:** once a staff has any schedule row, availability requires the slot to
  fall inside a shift for that weekday. No shift that weekday ⇒ unavailable (their day off by shift).
- **Time-off precedence:** a time-off window always wins. Full-day (null start/end) blocks the
  whole date; a partial window (start/end set) blocks slots inside it — covers both leave and a
  lunch break with one table.
- **Slot semantics:** a slot at `startTime` is available in a shift when `shift.start <= startTime
  < shift.end`; blocked by a partial time-off when `off.start <= startTime < off.end`.
- **Tenant safety:** schedules/time-off are always scoped to the staff's shop; the availability
  service only reads rows for the given staff. Never cross-shop.
- **Weekly PUT replace:** the schedule endpoint replaces the staff's whole week atomically
  (simplest correct model for a weekly grid UI).
- No payment code. Pure scheduling.

## Endpoints (tenant-scoped, staff verified to belong to shop)
- `GET  /shops/{shop}/staff/{staff}/schedule` — list weekly shifts.
- `PUT  /shops/{shop}/staff/{staff}/schedule` — replace week: `[{day_of_week,start_time,end_time}...]`.
- `GET  /shops/{shop}/staff/{staff}/time-off` — list windows.
- `POST /shops/{shop}/staff/{staff}/time-off` — add `{date, start_time?, end_time?, reason?}`.
- `DELETE /shops/{shop}/staff/{staff}/time-off/{timeOff}` — remove.

## Tests (TDD)
1. No schedule ⇒ available within shop hours (legacy).
2. Schedule-constrained: available inside shift, unavailable outside / on a no-shift weekday.
3. Full-day time-off blocks the date; partial time-off blocks only its window.
4. `StaffAssigner` skips an off / outside-shift staff and picks an available one.
5. Schedule PUT replaces the week; time-off POST/DELETE work and are tenant-scoped (staff↔shop).
