import { describe, it, expect } from 'vitest';
import type { Booking } from '@/types';
import {
  bookingDateKey,
  parseHM,
  serviceDuration,
  eventTimes,
  statusKind,
  addDays,
  addMonths,
  startOfWeek,
  weekDays,
  monthMatrix,
  groupByDay,
  hourRange,
  layoutDayEvents,
  isSameDay,
} from './calendar';

const bk = (over: Partial<Booking>): Booking => ({ id: 1, ...over });

describe('parseHM', () => {
  it('parses HH:MM and HH:MM:SS', () => {
    expect(parseHM('09:00')).toBe(540);
    expect(parseHM('09:30:00')).toBe(570);
    expect(parseHM('23:59')).toBe(1439);
  });
  it('rejects junk and out-of-range', () => {
    expect(parseHM('')).toBeNull();
    expect(parseHM(undefined)).toBeNull();
    expect(parseHM('nope')).toBeNull();
    expect(parseHM('25:00')).toBeNull();
  });
});

describe('bookingDateKey', () => {
  it('reads date then falls back to show_date', () => {
    expect(bookingDateKey(bk({ date: '2026-07-04 10:00:00' }))).toBe('2026-07-04');
    expect(bookingDateKey(bk({ date: undefined, show_date: '2026-07-05' }))).toBe('2026-07-05');
    expect(bookingDateKey(bk({}))).toBe('');
  });
});

describe('serviceDuration & eventTimes', () => {
  it('sums service durations', () => {
    expect(serviceDuration(bk({ services: [{ id: 1, duration: 30 }, { id: 2, duration: 45 }] }))).toBe(75);
    expect(serviceDuration(bk({ services: [] }))).toBeNull();
    expect(serviceDuration(bk({}))).toBeNull();
  });
  it('uses end_time when present', () => {
    expect(eventTimes(bk({ start_time: '09:00', end_time: '10:30' }))).toEqual({ startMin: 540, endMin: 630 });
  });
  it('falls back to service duration then 60m', () => {
    expect(eventTimes(bk({ start_time: '09:00', services: [{ id: 1, duration: 90 }] }))).toEqual({ startMin: 540, endMin: 630 });
    expect(eventTimes(bk({ start_time: '09:00' }))).toEqual({ startMin: 540, endMin: 600 });
  });
  it('ignores an end_time that is not after start', () => {
    expect(eventTimes(bk({ start_time: '09:00', end_time: '08:00' }))).toEqual({ startMin: 540, endMin: 600 });
  });
  it('returns nulls without a start time', () => {
    expect(eventTimes(bk({}))).toEqual({ startMin: null, endMin: null });
  });
});

describe('statusKind', () => {
  it('maps known statuses, defaulting to booked', () => {
    expect(statusKind('Completed')).toBe('completed');
    expect(statusKind('CANCELLED')).toBe('cancelled');
    expect(statusKind('queued')).toBe('queued');
    expect(statusKind('confirmed')).toBe('booked');
    expect(statusKind(undefined)).toBe('booked');
  });
});

describe('date arithmetic', () => {
  it('addDays / addMonths are immutable and correct', () => {
    const d = new Date(2026, 6, 4); // 4 Jul 2026
    expect(isSameDay(addDays(d, 3), new Date(2026, 6, 7))).toBe(true);
    expect(isSameDay(addDays(d, -4), new Date(2026, 5, 30))).toBe(true);
    expect(isSameDay(addMonths(d, 1), new Date(2026, 7, 1))).toBe(true);
    expect(isSameDay(d, new Date(2026, 6, 4))).toBe(true); // original untouched
  });
  it('startOfWeek returns Monday', () => {
    // 4 Jul 2026 is a Saturday → Monday is 29 Jun 2026
    expect(isSameDay(startOfWeek(new Date(2026, 6, 4)), new Date(2026, 5, 29))).toBe(true);
    // A Monday maps to itself
    expect(isSameDay(startOfWeek(new Date(2026, 5, 29)), new Date(2026, 5, 29))).toBe(true);
    // A Sunday maps back to the prior Monday
    expect(isSameDay(startOfWeek(new Date(2026, 6, 5)), new Date(2026, 5, 29))).toBe(true);
  });
  it('weekDays yields 7 consecutive days Mon→Sun', () => {
    const days = weekDays(new Date(2026, 6, 4));
    expect(days).toHaveLength(7);
    expect(days[0].getDay()).toBe(1); // Monday
    expect(days[6].getDay()).toBe(0); // Sunday
  });
});

describe('monthMatrix', () => {
  it('covers the whole month starting on a Monday', () => {
    const weeks = monthMatrix(new Date(2026, 6, 1)); // July 2026
    expect(weeks.every((w) => w.length === 7)).toBe(true);
    expect(weeks[0][0].getDay()).toBe(1); // first cell is a Monday
    const flat = weeks.flat();
    // every day of July present
    for (let day = 1; day <= 31; day++) {
      expect(flat.some((d) => d.getMonth() === 6 && d.getDate() === day)).toBe(true);
    }
  });
  it('does not emit a trailing week entirely outside the month', () => {
    const weeks = monthMatrix(new Date(2026, 1, 1)); // Feb 2026
    const last = weeks[weeks.length - 1];
    expect(last.some((d) => d.getMonth() === 1)).toBe(true);
  });
});

describe('groupByDay', () => {
  it('buckets by day and sorts by start time', () => {
    const map = groupByDay([
      bk({ id: 1, date: '2026-07-04', start_time: '11:00' }),
      bk({ id: 2, date: '2026-07-04', start_time: '09:00' }),
      bk({ id: 3, date: '2026-07-05', start_time: '10:00' }),
    ]);
    expect(map.get('2026-07-04')?.map((b) => b.id)).toEqual([2, 1]);
    expect(map.get('2026-07-05')?.map((b) => b.id)).toEqual([3]);
  });
  it('skips bookings without a date', () => {
    const map = groupByDay([bk({ id: 9 })]);
    expect(map.size).toBe(0);
  });
});

describe('hourRange', () => {
  it('keeps the default window when bookings fit', () => {
    expect(hourRange([bk({ start_time: '10:00', end_time: '11:00' })])).toEqual({ startHour: 8, endHour: 20 });
  });
  it('expands to fit early/late bookings', () => {
    const r = hourRange([
      bk({ start_time: '06:30', end_time: '07:00' }),
      bk({ start_time: '21:15', end_time: '22:30' }),
    ]);
    expect(r.startHour).toBe(6);
    expect(r.endHour).toBe(23);
  });
});

describe('layoutDayEvents', () => {
  it('places non-overlapping events in a single column', () => {
    const placed = layoutDayEvents([
      bk({ id: 1, start_time: '09:00', end_time: '10:00' }),
      bk({ id: 2, start_time: '10:00', end_time: '11:00' }),
    ]);
    expect(placed.map((p) => ({ id: p.booking.id, col: p.col, cols: p.cols }))).toEqual([
      { id: 1, col: 0, cols: 1 },
      { id: 2, col: 0, cols: 1 },
    ]);
  });
  it('splits two overlapping events into two columns', () => {
    const placed = layoutDayEvents([
      bk({ id: 1, start_time: '09:00', end_time: '10:30' }),
      bk({ id: 2, start_time: '09:30', end_time: '10:00' }),
    ]);
    expect(placed).toHaveLength(2);
    expect(placed.every((p) => p.cols === 2)).toBe(true);
    expect(new Set(placed.map((p) => p.col))).toEqual(new Set([0, 1]));
  });
  it('reuses a freed column after an event ends', () => {
    const placed = layoutDayEvents([
      bk({ id: 1, start_time: '09:00', end_time: '10:00' }),
      bk({ id: 2, start_time: '09:00', end_time: '11:00' }),
      bk({ id: 3, start_time: '10:00', end_time: '11:00' }),
    ]);
    // 1 & 2 overlap → 2 cols; 3 starts when 1 ends but still overlaps 2 → same cluster, 2 cols
    expect(placed.every((p) => p.cols === 2)).toBe(true);
    const byId = new Map(placed.map((p) => [p.booking.id, p]));
    expect(byId.get(3)?.col).toBe(0); // reuses column freed by #1
  });
  it('skips bookings without a start time', () => {
    expect(layoutDayEvents([bk({ id: 1 })])).toHaveLength(0);
  });
});
