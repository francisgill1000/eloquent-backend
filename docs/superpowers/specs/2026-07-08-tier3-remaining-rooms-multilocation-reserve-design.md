# Tier 3 Remaining — Rooms/Resources, Multi-Location, Google Reserve/IG — Design + Plans (SPEC ONLY)

The last three Tier 3 items. Documented 2026-07-08 (autonomous overnight build). These are
**spec-only** — not because of payment, but because each is either a larger structural change
(multi-location) or an external-partner integration needing credentials/approval not available
in an unattended run (Google Reserve/IG). Rooms/Resources is fully buildable and has the
tightest plan; it's the natural next build.

---

## 1. Rooms / Resources (buildable next — no payment)
**Problem:** some services need a finite resource beyond staff — a treatment room, a chair, a
laser machine. Two bookings can need the same room even with different staff.

**Plan (fits the existing pattern):**
- `resources` table (shop_id, name, type, is_active) — the bookable things.
- `service_resource` pivot (catalog_id → resource requirement) OR a simpler
  `catalogs.requires_resource_type` — which resource a service consumes.
- `booking_resource` pivot (booking_id, resource_id) — the assignment.
- A `ResourceAssigner` mirroring `StaffAssigner`: at booking time, pick a free resource of the
  required type for the slot; if none free, `queued` (same waitlist semantics).
- Extend `BookingCreator` to call it alongside `StaffAssigner`; a booking is only `booked` when
  BOTH a staff AND every required resource are free — else queued.
- `sweep()` must also consider resource freeing.
- **Why not built tonight:** it changes the core booked/queued decision (currently staff-only),
  so it needs careful conflict tests across staff×resource to avoid regressing the 332-green
  suite. It's the highest-value remaining build and should be its own focused session.
- **Tests:** resource conflict queues; freeing a resource sweeps; per-service resource
  requirement; tenant-scoped.

## 2. Multi-Location (branches under one business) — larger structural
**Problem:** a business with multiple branches wants one login, per-branch calendars/staff/hours.

**Plan:**
- Today a "shop" IS the tenant AND the location. Cleanest model: introduce `locations`
  (business_id, name, address, lat/lon, timezone) and move location-scoped data
  (working_hours, staff, bookings, resources) to reference `location_id`, while `shops`
  becomes the business/tenant. This is a **big migration + backfill** (every existing shop → one
  business + one default location) and touches auth, RBAC scoping, every booking query, and the
  admin nav.
- Lower-risk incremental alternative: a `parent_shop_id` self-reference on `shops` so branches
  are sibling shops grouped under a parent, with a business switcher in the SPA. Reuses all
  existing per-shop code; the only new work is grouping + a switcher + roll-up analytics.
  **Recommended** first step — ship the switcher + parent grouping, defer the full normalisation.
- **Why not built tonight:** touches the tenancy spine (RBAC, every scoped query) — too broad to
  land safely unattended without risking the green suite and prod data model. Needs Francis's call
  on the `parent_shop_id` vs full-normalisation trade-off.
- **Tests:** branch grouping, per-branch scoping isolation, roll-up analytics across branches.

## 3. Google Reserve / Instagram "Book Now" — external integration
**Problem:** let customers book from Google Search/Maps and Instagram — external discovery → the
shop's calendar.

**Plan:**
- **Instagram "Book Now":** the lightest win — IG action buttons link to a URL. Expose a public
  per-shop booking URL (`{customer_url}/book/{shop_code}`, likely already serveable) and document
  wiring it as the IG/Facebook "Book Now" / "Book an appointment" action button. Mostly config +
  a public deep link; minimal code.
- **Google Reserve ("Reserve with Google"):** a formal partner program. Requires either an
  approved aggregator/partner integration or the Maps Booking API with an onboarded feed
  (Availability + Booking Server implementing Google's spec, sandbox certification, and a business
  agreement). Needs credentials + Google approval → cannot be done unattended.
  - Build shape when greenlit: a Booking Server exposing Google's `CheckAvailability` +
    `CreateBooking` RPCs backed by our slot/`BookingCreator` logic, plus a nightly availability
    feed. Reuses `Shop::getSlots` + `BookingCreator` under the hood.
- **Why not built tonight:** external onboarding/credentials + certification; no code path can be
  verified against staging without the partner sandbox.

---

## Recommended sequence for Francis
1. **Rooms/Resources** (own focused session — buildable now, highest value).
2. **Deposits/no-show payment layer** + **loyalty ledger** (from the Tier 1/2 payment specs).
3. **Multi-location** via `parent_shop_id` + switcher (incremental).
4. **IG Book Now** (config) → **Google Reserve** (partner onboarding) last.
