/**
 * Date-range helpers shared by the report pages (/insights, /hunt-insights).
 * Lifted verbatim out of Insights.tsx when the Hunt dashboard needed them —
 * behaviour is unchanged, see Insights.test.tsx.
 */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const pad = (n: number) => String(n).padStart(2, '0');

export const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const parseISO = (s: string) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
export const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
export const daysBetween = (from: string, to: string) =>
  Math.round((parseISO(to).getTime() - parseISO(from).getTime()) / 86_400_000) + 1;
export const fmtLong = (s: string) => { const d = parseISO(s); return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`; };
export const fmtShort = (s: string) => { const d = parseISO(s); return `${d.getDate()} ${MONTHS[d.getMonth()]}`; };
export const fmtNum = (n: number) => n.toLocaleString();

export type PresetKey = '7d' | '30d' | '90d' | 'mtd' | 'lastmonth' | 'ytd' | 'custom';

export const PRESETS: { key: Exclude<PresetKey, 'custom'>; label: string }[] = [
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '90d', label: '90 days' },
  { key: 'mtd', label: 'This month' },
  { key: 'lastmonth', label: 'Last month' },
  { key: 'ytd', label: 'This year' },
];

export function presetRange(key: Exclude<PresetKey, 'custom'>, today: Date): { from: string; to: string } {
  const y = today.getFullYear();
  const m = today.getMonth();
  switch (key) {
    case '7d': return { from: iso(addDays(today, -6)), to: iso(today) };
    case '30d': return { from: iso(addDays(today, -29)), to: iso(today) };
    case '90d': return { from: iso(addDays(today, -89)), to: iso(today) };
    case 'mtd': return { from: iso(new Date(y, m, 1)), to: iso(new Date(y, m + 1, 0)) };
    case 'lastmonth': return { from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) };
    case 'ytd': return { from: iso(new Date(y, 0, 1)), to: iso(today) };
  }
}

/** The equal-length window immediately before [from, to], for deltas. */
export function previousRange(from: string, to: string): { from: string; to: string } {
  const len = daysBetween(from, to);
  const pTo = iso(addDays(parseISO(from), -1));
  return { from: iso(addDays(parseISO(pTo), -(len - 1))), to: pTo };
}

/** Percent change, or null when there's no prior figure to compare against. */
export const pctChange = (cur: number, prev: number): number | null => {
  if (prev === 0) return cur === 0 ? 0 : null;
  return ((cur - prev) / prev) * 100;
};
