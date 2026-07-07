import { describe, it, expect } from 'vitest';
import { dragIndexFromPointer, snapIndex, SWITCH_RAIL } from './statusKnob';

// railTop = 0 keeps pointerY equal to the position within the rail.
describe('dragIndexFromPointer', () => {
  it('maps each option centre to its exact whole index', () => {
    expect(dragIndexFromPointer(36, 0)).toBe(0); // Queued
    expect(dragIndexFromPointer(108, 0)).toBe(1); // Booked
    expect(dragIndexFromPointer(180, 0)).toBe(2); // Completed
    expect(dragIndexFromPointer(252, 0)).toBe(3); // Cancelled
  });

  it('returns fractional positions between options', () => {
    expect(dragIndexFromPointer(72, 0)).toBeCloseTo(0.5);
    expect(dragIndexFromPointer(144, 0)).toBeCloseTo(1.5);
  });

  it('subtracts the rail top offset', () => {
    expect(dragIndexFromPointer(136, 100)).toBe(0); // 136-100 = 36 → index 0
    expect(dragIndexFromPointer(208, 100)).toBe(1);
  });

  it('clamps above the first option', () => {
    expect(dragIndexFromPointer(0, 0)).toBe(0);
    expect(dragIndexFromPointer(-500, 0)).toBe(0);
  });

  it('clamps below the last option', () => {
    expect(dragIndexFromPointer(999, 0)).toBe(SWITCH_RAIL.count - 1);
  });
});

describe('snapIndex', () => {
  it('rounds to the nearest whole status', () => {
    expect(snapIndex(0.49)).toBe(0);
    expect(snapIndex(0.5)).toBe(1);
    expect(snapIndex(2.4)).toBe(2);
    expect(snapIndex(2.6)).toBe(3);
  });

  it('clamps out-of-range positions into [0, count-1]', () => {
    expect(snapIndex(-3)).toBe(0);
    expect(snapIndex(99)).toBe(SWITCH_RAIL.count - 1);
  });
});
