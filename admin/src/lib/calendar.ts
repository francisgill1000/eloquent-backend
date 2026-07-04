import type { Booking } from '@/types';

// ── Pure calendar helpers ────────────────────────────────────────────────────
// All date math is local-time and side-effect free so the month grid, week
// layout and event placement can be unit-tested without a DOM.

export type StatusKind = 'booked' | 'completed' | 'cancelled' | 'queued';

export type PlacedEvent = {
  booking: Booking;
  startMin: number; // minutes since local midnight
  endMin: number;
  col: number; // 0-based column within its overlap cluster
  cols: number; // total columns in that cluster
};

const DEFAULT_DURATION_MIN = 60;
const MIN_EVENT_MIN = 20; // floor so a zero-length booking still renders a block

/** Local YYYY-MM-DD key a booking falls on (date, falling back to show_date). */
export function bookingDateKey(b: Booking): string {
  return String(b.date ?? b.show_date ?? '').slice(0, 10);
}

/** Parse "HH:MM" / "HH:MM:SS" into minutes since midnight, or null. */
export function parseHM(t?: string | null): number | null {
  if (!t) return null;
  const m = /^(\d{1,2}):(\d{2})/.exec(String(t).trim());
  if (!m) return null;
  const h = Number(m[1]);
  const min = Number(m[2]);
  if (h > 23 || min > 59) return null;
  return h * 60 + min;
}

/** Summed service duration (minutes) for a booking, or null when unknown. */
export function serviceDuration(b: Booking): number | null {
  if (!b.services?.length) return null;
  const sum = b.services.reduce((s, sv) => s + (Number(sv.duration) || 0), 0);
  return sum > 0 ? sum : null;
}

/** Resolve a booking's start/end minutes; end falls back to service duration → 60m. */
export function eventTimes(b: Booking): { startMin: number | null; endMin: number | null } {
  const startMin = parseHM(b.start_time);
  if (startMin == null) return { startMin: null, endMin: null };
  let endMin = parseHM(b.end_time);
  if (endMin == null || endMin <= startMin) {
    endMin = startMin + (serviceDuration(b) ?? DEFAULT_DURATION_MIN);
  }
  return { startMin, endMin: Math.max(endMin, startMin + MIN_EVENT_MIN) };
}

export function statusKind(status?: string): StatusKind {
  const s = String(status ?? '').toLowerCase();
  if (s === 'completed') return 'completed';
  if (s === 'cancelled') return 'cancelled';
  if (s === 'queued') return 'queued';
  return 'booked';
}

// ── Date arithmetic (local, immutable) ───────────────────────────────────────

export function addDays(d: Date, n: number): Date {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
}

/** First day of a month n months from d (day pinned to the 1st). */
export function addMonths(d: Date, n: number): Date {
  return new Date(d.getFullYear(), d.getMonth() + n, 1);
}

export function isSameDay(a: Date, b: Date): boolean {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

/** Monday-of-the-week for the given date (week starts Monday). */
export function startOfWeek(d: Date): Date {
  const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const offset = (x.getDay() + 6) % 7; // Mon → 0 … Sun → 6
  return addDays(x, -offset);
}

/** The 7 dates (Mon…Sun) of the week containing d. */
export function weekDays(d: Date): Date[] {
  const s = startOfWeek(d);
  return Array.from({ length: 7 }, (_, i) => addDays(s, i));
}

/**
 * Weeks (rows of 7 Mon…Sun dates) covering the month of `monthDate`, including
 * leading/trailing days from adjacent months. Trailing weeks that fall entirely
 * outside the month are trimmed so short months don't render an empty row.
 */
export function monthMatrix(monthDate: Date): Date[][] {
  const first = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
  let cur = startOfWeek(first);
  const weeks: Date[][] = [];
  for (let w = 0; w < 6; w++) {
    weeks.push(Array.from({ length: 7 }, (_, i) => addDays(cur, i)));
    cur = addDays(cur, 7);
  }
  while (weeks.length > 4 && weeks[weeks.length - 1].every((d) => d.getMonth() !== monthDate.getMonth())) {
    weeks.pop();
  }
  return weeks;
}

/** Group bookings by local day key, each day's list sorted by start time. */
export function groupByDay(bookings: Booking[]): Map<string, Booking[]> {
  const map = new Map<string, Booking[]>();
  for (const b of bookings) {
    const key = bookingDateKey(b);
    if (!key) continue;
    const arr = map.get(key);
    if (arr) arr.push(b);
    else map.set(key, [b]);
  }
  for (const arr of map.values()) {
    arr.sort((a, b) => (parseHM(a.start_time) ?? Number.MAX_SAFE_INTEGER) - (parseHM(b.start_time) ?? Number.MAX_SAFE_INTEGER));
  }
  return map;
}

/**
 * Visible hour range [startHour, endHour) for the week grid. Starts from a
 * sensible default window and expands to fit any bookings that fall outside it.
 */
export function hourRange(bookings: Booking[], defStart = 8, defEnd = 20): { startHour: number; endHour: number } {
  let min = defStart * 60;
  let max = defEnd * 60;
  for (const b of bookings) {
    const { startMin, endMin } = eventTimes(b);
    if (startMin == null) continue;
    min = Math.min(min, startMin);
    if (endMin != null) max = Math.max(max, endMin);
  }
  const startHour = Math.max(0, Math.floor(min / 60));
  const endHour = Math.min(24, Math.ceil(max / 60));
  return { startHour, endHour: Math.max(endHour, startHour + 1) };
}

/**
 * Assign side-by-side columns to a day's timed bookings so overlapping events
 * sit next to each other. Events are greedily packed into the first free column;
 * every event in an overlap cluster shares the cluster's column count so their
 * rendered widths line up. Bookings without a start time are skipped.
 */
export function layoutDayEvents(bookings: Booking[]): PlacedEvent[] {
  const items = bookings
    .map((booking) => {
      const { startMin, endMin } = eventTimes(booking);
      return startMin == null ? null : { booking, startMin, endMin: endMin as number };
    })
    .filter((x): x is { booking: Booking; startMin: number; endMin: number } => x !== null)
    .sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin);

  const out: PlacedEvent[] = [];
  let cluster: PlacedEvent[] = [];
  let clusterMaxEnd = -1;
  const colEnds: number[] = []; // last endMin per column in the current cluster

  const finalize = () => {
    const cols = colEnds.length || 1;
    for (const e of cluster) e.cols = cols;
    out.push(...cluster);
    cluster = [];
    colEnds.length = 0;
    clusterMaxEnd = -1;
  };

  for (const it of items) {
    if (cluster.length && it.startMin >= clusterMaxEnd) finalize();
    let col = colEnds.findIndex((end) => end <= it.startMin);
    if (col === -1) {
      col = colEnds.length;
      colEnds.push(it.endMin);
    } else {
      colEnds[col] = it.endMin;
    }
    cluster.push({ ...it, col, cols: 1 });
    clusterMaxEnd = Math.max(clusterMaxEnd, it.endMin);
  }
  if (cluster.length) finalize();
  return out;
}

// ── Formatting ───────────────────────────────────────────────────────────────

export function monthTitle(d: Date): string {
  return d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

export function weekRangeTitle(d: Date): string {
  const days = weekDays(d);
  const a = days[0];
  const b = days[6];
  const sameMonth = a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear();
  const left = a.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  const right = b.toLocaleDateString('en-US', sameMonth ? { day: 'numeric', year: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' });
  return `${left} – ${right}`;
}

/** "9 AM", "12 PM" for hour-gutter labels. */
export function fmtHour(h: number): string {
  const period = h < 12 || h === 24 ? 'AM' : 'PM';
  const hr = h % 12 === 0 ? 12 : h % 12;
  return `${hr} ${period}`;
}

/** "9 AM" / "9:30 AM" from minutes-since-midnight for event labels. */
export function fmtTimeMin(min: number): string {
  const h = Math.floor(min / 60);
  const m = min % 60;
  const period = h < 12 ? 'AM' : 'PM';
  const hr = h % 12 === 0 ? 12 : h % 12;
  return m === 0 ? `${hr} ${period}` : `${hr}:${String(m).padStart(2, '0')} ${period}`;
}
