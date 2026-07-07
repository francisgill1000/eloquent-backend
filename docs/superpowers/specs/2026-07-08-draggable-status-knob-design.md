# Draggable status knob — design

**Date:** 2026-07-08
**Surface:** Booking detail page ([admin/src/pages/BookingAction.tsx](../../../admin/src/pages/BookingAction.tsx)), styles in [admin/src/styles/customer.css](../../../admin/src/styles/customer.css)

## Goal

Let the user change a booking's status by **dragging the status knob** up/down the
rail, in addition to the existing click-a-label behavior. Releasing snaps the knob
to the nearest status and commits it through the same confirm-gated path already in
place.

## Current state

The status switch is a vertical rail with 4 options top→bottom: **Queued, Booked,
Completed, Cancelled** (`SWITCH_OPTS`). The knob is positioned purely by CSS:

```css
.ba-switch-knob { top: calc(36px + var(--active) * 72px); transition: top .38s …; }
.ba-switch-fill { height: calc(22px + var(--active) * 72px); transition: height …; }
```

`--active` is the integer status index (0–3). Clicking a label calls
`updateStatus(label)` → `window.confirm(...)` → `setBookingStatus(...)` → refetch.
Each status is **72px** apart on the rail; the first sits at **36px** from the top.

## Interaction

- **Pointer down on the knob** starts a drag (Pointer Events → one path for mouse +
  touch; `setPointerCapture` so fast moves keep tracking).
- **Pointer move**: the knob follows the pointer live, clamped to `[0, 3]`. The fill
  and glow track the same fractional position in real time. CSS transitions on the
  knob/fill are disabled during the drag (via a `dragging` class) so movement is 1:1.
- **Pointer up**: `Math.round(position)` → target index. Transitions re-enable so the
  snap animates.
  - Different from current status → existing `updateStatus(label)` runs
    (`window.confirm`). **OK** commits; **Cancel** leaves status unchanged.
  - Same as current status → no-op.
  - Either way, drag state clears and `--active` reverts to the real
    `booking.status` index — so a cancelled confirm springs the knob back.
- **Clicking a label is unchanged** — purely additive.

## Geometry (the testable core)

Extract a pure helper so the risky math is unit-tested independently of the DOM:

```
dragIndexFromPointer(pointerY, railTop, { first: 36, step: 72, count: 4 })
  = clamp(0, count-1, (pointerY - railTop - first) / step)   // fractional, for live position
snapIndex(position) = Math.round(clamp(0, count-1, position))
```

`railTop` comes from the knob's offset parent (`.ba-switch`) via
`getBoundingClientRect().top` captured at pointer-down.

## Component changes (BookingAction.tsx)

- New state: `drag: { pos: number } | null` (live fractional position, null when idle).
- `onPointerDown` on `.ba-switch-knob`: capture pointer + `.ba-switch` rect top,
  set `drag={pos: switchActive}`, mark dragging.
- `onPointerMove`: `setDrag({ pos: dragIndexFromPointer(e.clientY, railTop, …) })`.
- `onPointerUp`: read `snapIndex(drag.pos)`; clear `drag`; if index ≠ current and in
  range, `void updateStatus(SWITCH_OPTS[index].label)`.
- While `drag` is set, override the inline `--active` with `drag.pos` and add a
  `dragging` class to `.ba-switch`.
- Guard: ignore drag start while `busy` (a status write is already in flight).

## CSS changes (customer.css)

- `.ba-switch-knob { cursor: grab; touch-action: none; }`
- `.ba-switch.dragging .ba-switch-knob { cursor: grabbing; }`
- `.ba-switch.dragging .ba-switch-knob, .ba-switch.dragging .ba-switch-fill {
  transition: none; }` — 1:1 tracking during drag, snap animates on release.

## Testing

- Unit-test `dragIndexFromPointer` + `snapIndex`: clamping at both ends, midpoint
  rounding, exact-hit values (36px→0, 108px→1, 180px→2, 252px→3).
- Existing `BookingAction.test.tsx` continues to cover the click/confirm path
  (unchanged code path).

## Out of scope

No backend changes, no new dependencies, no change to `setBookingStatus` or the
confirm dialog. Instant-commit / Undo-toast variant explicitly rejected in favor of
confirm-after-release.
