// ── Pure geometry for the draggable status knob ──────────────────────────────
// The status switch rail places option 0 at `first` px from the rail top and
// spaces each subsequent option `step` px below. These helpers convert a pointer
// position into a fractional knob index (for live tracking) and snap it to the
// nearest whole status. Kept side-effect free so the drag math is unit-tested
// without a DOM. See BookingAction.tsx / customer.css (.ba-switch-knob).

export type RailGeometry = { first: number; step: number; count: number };

/** Rail metrics baked into customer.css: knob center at 36px + index*72px. */
export const SWITCH_RAIL: RailGeometry = { first: 36, step: 72, count: 4 };

function clamp(v: number, lo: number, hi: number): number {
  return v < lo ? lo : v > hi ? hi : v;
}

/**
 * Fractional knob index for a live pointer, clamped to [0, count-1].
 * `pointerY` and `railTop` are viewport-relative (clientY / rect.top).
 */
export function dragIndexFromPointer(
  pointerY: number,
  railTop: number,
  geo: RailGeometry = SWITCH_RAIL,
): number {
  const raw = (pointerY - railTop - geo.first) / geo.step;
  return clamp(raw, 0, geo.count - 1);
}

/** Nearest whole status index for a (possibly fractional) position. */
export function snapIndex(position: number, geo: RailGeometry = SWITCH_RAIL): number {
  return Math.round(clamp(position, 0, geo.count - 1));
}
