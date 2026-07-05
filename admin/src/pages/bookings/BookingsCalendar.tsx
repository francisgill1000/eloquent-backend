import { useMemo, useState } from 'react';
import { Icons } from '@/components/Icons';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { formatLocalDate } from '@/lib/date';
import type { Booking } from '@/types';
import {
  addDays,
  addMonths,
  weekDays,
  bookingDateKey,
  parseHM,
  fmtTimeMin,
  statusKind,
  monthTitle,
  weekRangeTitle,
} from '@/lib/calendar';
import { MonthView } from './MonthView';
import { WeekView } from './WeekView';

type ViewKey = 'month' | 'week' | 'list';
const VIEWS: { key: ViewKey; label: string }[] = [
  { key: 'month', label: 'Month' },
  { key: 'week', label: 'Week' },
  { key: 'list', label: 'List' },
];

type Filter = 'All' | 'Queued' | 'Booked' | 'Completed' | 'Cancelled';

// Status funnel chips (mirrors the leads funnel): label + accent + the
// statusKind() bucket the count comes from.
const STAT_CHIPS: { filter: Filter; label: string; kind: 'all' | 'queued' | 'booked' | 'completed' | 'cancelled' }[] = [
  { filter: 'All', label: 'All', kind: 'all' },
  { filter: 'Queued', label: 'Queued', kind: 'queued' },
  { filter: 'Booked', label: 'Booked', kind: 'booked' },
  { filter: 'Completed', label: 'Completed', kind: 'completed' },
  { filter: 'Cancelled', label: 'Cancelled', kind: 'cancelled' },
];

function chipClass(status: string): string {
  const kind = statusKind(status);
  if (kind === 'completed') return 'c-chip c-chip-completed';
  if (kind === 'cancelled') return 'c-chip c-chip-cancelled';
  if (kind === 'queued') return 'c-chip c-chip-pending';
  return 'c-chip c-chip-booked';
}

function aed(n: number): string {
  return `AED ${Math.round(n).toLocaleString()}`;
}

type Props = {
  bookings: Booking[];
  loading: boolean;
  error: string;
  onOpen: (id: number) => void;
};

export function BookingsCalendar({ bookings, loading, error, onOpen }: Props) {
  const [view, setView] = useState<ViewKey>('month');
  const [anchor, setAnchor] = useState<Date>(() => new Date());
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<Filter>('All');

  const today = useMemo(() => new Date(), []);
  const todayISO = formatLocalDate(today);

  // Per-status counts for the funnel chips, over all loaded bookings.
  const counts = useMemo(() => {
    const c = { all: bookings.length, queued: 0, booked: 0, completed: 0, cancelled: 0 };
    for (const b of bookings) c[statusKind(b.status)]++;
    return c;
  }, [bookings]);

  // Status + search filter, shared across all three views.
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return bookings.filter((b) => {
      const matchFilter = filter === 'All' || statusKind(b.status) === filter.toLowerCase();
      if (!matchFilter) return false;
      if (!q) return true;
      return (
        (b.customer?.name || b.customer_name || '').toLowerCase().includes(q) ||
        (b.booking_reference || '').toLowerCase().includes(q)
      );
    });
  }, [bookings, filter, search]);

  // Bookings within the currently visible calendar range.
  const weekSet = useMemo(() => new Set(weekDays(anchor).map(formatLocalDate)), [anchor]);
  const visible = useMemo(() => {
    if (view === 'list') return filtered;
    if (view === 'week') return filtered.filter((b) => weekSet.has(bookingDateKey(b)));
    const ym = `${anchor.getFullYear()}-${String(anchor.getMonth() + 1).padStart(2, '0')}`;
    return filtered.filter((b) => bookingDateKey(b).startsWith(ym));
  }, [filtered, view, anchor, weekSet]);

  const listRows = useMemo(
    () =>
      [...filtered].sort((a, b) => {
        const da = bookingDateKey(a);
        const db = bookingDateKey(b);
        if (da !== db) return db.localeCompare(da); // newest day first
        return (parseHM(a.start_time) ?? 0) - (parseHM(b.start_time) ?? 0);
      }),
    [filtered],
  );

  const rangeLabel = view === 'week' ? weekRangeTitle(anchor) : view === 'month' ? monthTitle(anchor) : 'All bookings';
  const revenue = visible.reduce((s, b) => (statusKind(b.status) === 'cancelled' ? s : s + Number(b.charges ?? 0)), 0);
  const count = view === 'list' ? filtered.length : visible.length;

  const step = (dir: -1 | 1) => {
    setAnchor((a) => (view === 'month' ? addMonths(a, dir) : addDays(a, dir * 7)));
  };
  const goToday = () => setAnchor(new Date());
  const selectDay = (day: Date) => {
    setAnchor(day);
    setView('week');
  };

  return (
    <div className="c-bk">
      {/* Page header — title + range summary, view switcher + date nav */}
      <div className="c-bk-head">
        <div className="c-bk-head-text">
          <h1 className="c-bk-title">Bookings</h1>
          <p className="c-bk-sub">
            {rangeLabel} · {count} {count === 1 ? 'booking' : 'bookings'}
            {view !== 'list' && <> · {aed(revenue)}</>}
          </p>
        </div>
        <div className="c-bk-head-actions">
          {view !== 'list' && (
            <div className="c-bk-nav">
              <button type="button" className="c-bk-nav-btn" onClick={() => step(-1)} aria-label="Previous">
                <Icons.ChevronLeft size={18} />
              </button>
              <button type="button" className="c-bk-today" onClick={goToday}>Today</button>
              <button type="button" className="c-bk-nav-btn" onClick={() => step(1)} aria-label="Next">
                <Icons.Chevron size={18} />
              </button>
            </div>
          )}
          <div className="c-seg" role="tablist" aria-label="Calendar view">
            {VIEWS.map((v) => (
              <button
                key={v.key}
                type="button"
                role="tab"
                aria-selected={view === v.key}
                className={`c-seg-btn${view === v.key ? ' on' : ''}`}
                onClick={() => setView(v.key)}
              >
                {v.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Status funnel — count per status, doubles as the filter (like leads) */}
      <div className="c-bk-funnel">
        {STAT_CHIPS.map((s) => (
          <button
            key={s.filter}
            type="button"
            className={`lf-fchip bk-s-${s.kind}${filter === s.filter ? ' on' : ''}${counts[s.kind] === 0 ? ' zero' : ''}`}
            onClick={() => setFilter((cur) => (cur === s.filter ? 'All' : s.filter))}
          >
            <span className="lf-fchip-n">{counts[s.kind]}</span>
            <span className="lf-fchip-l">{s.label}</span>
          </button>
        ))}
      </div>

      {/* Controls — search, applies to every view */}
      <div className="c-bk-controls">
        <div className="c-input-row c-bk-search">
          <Icons.Search size={18} />
          <input
            type="text"
            placeholder="Search by name or reference…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      {error && <div className="c-error-box" style={{ margin: '0 0 12px' }}>{error}</div>}

      {loading ? (
        <Spinner label="Loading bookings…" />
      ) : view === 'list' ? (
        listRows.length > 0 ? (
          <div className="c-dtable-wrap">
            <table className="c-dtable">
              <thead>
                <tr>
                  <th style={{ width: 96 }}>When</th>
                  <th>Customer</th>
                  <th>Service</th>
                  <th style={{ width: 130 }}>Staff</th>
                  <th style={{ width: 116 }}>Status</th>
                  <th className="ta-r" style={{ width: 104 }}>Amount</th>
                </tr>
              </thead>
              <tbody>
                {listRows.map((b) => {
                  const status = String(b.status || 'Booked');
                  const name = b.customer?.name || b.customer_name || 'Guest';
                  const services = b.services?.map((s) => s.title || s.name).filter(Boolean).join(', ') || 'Service';
                  const day = bookingDateKey(b);
                  const isToday = day === todayISO;
                  const dateLabel = !day ? 'TBD' : isToday ? 'Today' : day.slice(5).replace('-', '/');
                  const startMin = parseHM(b.start_time);
                  const when = startMin != null ? fmtTimeMin(startMin) : (b.show_date ?? '');
                  return (
                    <tr key={b.id} className="c-dt-click" onClick={() => onOpen(b.id)}>
                      <td className="c-dt-namecell">
                        <span className="c-dt-name">{dateLabel}</span>
                        {when ? <span className="c-dt-sub">{when}</span> : null}
                      </td>
                      <td className="c-dt-namecell"><span className="c-dt-name">{name}</span></td>
                      <td className="c-dt-namecell"><span className="c-dt-sub">{services}</span></td>
                      <td className="c-dt-namecell"><span className="c-dt-sub">{b.staff?.name ?? '—'}</span></td>
                      <td><span className={chipClass(status)}>{status}</span></td>
                      <td className="ta-r"><span className="c-dt-price">AED {b.charges ?? 0}</span></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="c-bk-card c-bk-empty">
            <EmptyState
              icon={<span className="c-empty-badge"><Icons.Calendar size={26} /></span>}
              title={search || filter !== 'All' ? 'No matching bookings' : 'No bookings yet'}
              subtitle={search || filter !== 'All' ? 'Try a different filter or search term.' : 'New bookings from your customers will show up here.'}
            />
          </div>
        )
      ) : (
        <div className="c-bk-card">
          {view === 'month' ? (
            <MonthView monthDate={anchor} bookings={visible} today={today} onOpen={onOpen} onSelectDay={selectDay} />
          ) : (
            <WeekView weekDates={weekDays(anchor)} bookings={visible} today={today} now={today} onOpen={onOpen} />
          )}
          {visible.length === 0 && (
            <div className="c-bk-range-empty">
              No bookings {view === 'week' ? 'this week' : 'this month'}
              {(search || filter !== 'All') && ' matching your filter'}.
            </div>
          )}
        </div>
      )}
    </div>
  );
}
