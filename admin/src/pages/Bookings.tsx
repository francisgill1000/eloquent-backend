import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { AppBar } from '@/layout/AppBar';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getShopBookings } from '@/lib/bookings';
import { formatLocalDate } from '@/lib/date';
import { statusKind } from '@/lib/calendar';
import { useIsDesktop } from '@/hooks/useIsDesktop';
import { BookingsCalendar } from './bookings/BookingsCalendar';
import type { Booking } from '@/types';

type Filter = 'All' | 'Queued' | 'Booked' | 'Completed' | 'Cancelled';
const STAT_CHIPS: { filter: Filter; label: string; kind: 'all' | 'queued' | 'booked' | 'completed' | 'cancelled' }[] = [
  { filter: 'All', label: 'All', kind: 'all' },
  { filter: 'Queued', label: 'Queued', kind: 'queued' },
  { filter: 'Booked', label: 'Booked', kind: 'booked' },
  { filter: 'Completed', label: 'Completed', kind: 'completed' },
  { filter: 'Cancelled', label: 'Cancelled', kind: 'cancelled' },
];

function chipClass(status: string): string {
  const s = status.toLowerCase();
  if (s === 'completed') return 'c-chip c-chip-completed';
  if (s === 'cancelled') return 'c-chip c-chip-cancelled';
  if (s === 'queued') return 'c-chip c-chip-pending';
  return 'c-chip c-chip-booked';
}

function formatTime(t?: string): string {
  if (!t) return 'TBD';
  try {
    const norm = t.length === 5 ? `${t}:00` : t;
    return new Date(`1970-01-01T${norm}`).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  } catch { return t; }
}

function dateOf(b: Booking): string {
  return String(b.date ?? '').slice(0, 10);
}

export default function Bookings() {
  const navigate = useNavigate();
  const { shop, logoutShop } = useShop();
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<Filter>('All');
  const isDesktop = useIsDesktop();

  const fetchBookings = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true);
    setError('');
    try {
      const res = await getShopBookings(shop.id);
      setBookings(res.data);
    } catch (e: unknown) {
      if ((e as { response?: { status?: number } })?.response?.status === 401) { logoutShop(); navigate('/login'); return; }
      setError('Could not load bookings.');
    } finally {
      setLoading(false);
    }
  }, [shop?.id, logoutShop, navigate]);

  useEffect(() => { void fetchBookings(); }, [fetchBookings]);

  // Desktop (≥1024px) gets the full calendar experience; mobile/tablet keep the
  // list below, unchanged.
  if (isDesktop) {
    return (
      <div className="m-screen c-bookings">
        <div className="m-scroll">
          <BookingsCalendar
            bookings={bookings}
            loading={loading}
            error={error}
            onOpen={(id) => navigate(`/booking/${id}`)}
          />
        </div>
      </div>
    );
  }

  const filtered = bookings.filter((b) => {
    const matchFilter = filter === 'All' || statusKind(b.status) === filter.toLowerCase();
    const q = search.toLowerCase();
    const matchSearch = !q
      || (b.customer?.name || b.customer_name || '').toLowerCase().includes(q)
      || (b.booking_reference || '').toLowerCase().includes(q);
    return matchFilter && matchSearch;
  });

  const counts = { all: bookings.length, queued: 0, booked: 0, completed: 0, cancelled: 0 };
  for (const b of bookings) counts[statusKind(b.status)]++;

  const todayISO = formatLocalDate(new Date());

  return (
    <div className="m-screen c-dash">
      <AppBar title="Bookings" sub={`${filtered.length} shown`} />
      <div className="m-scroll">
        <div className="c-bk-funnel">
          {STAT_CHIPS.map((s) => (
            <button
              key={s.filter}
              aria-label={s.label}
              className={`lf-fchip bk-s-${s.kind}${filter === s.filter ? ' on' : ''}${counts[s.kind] === 0 ? ' zero' : ''}`}
              onClick={() => setFilter((cur) => (cur === s.filter ? 'All' : s.filter))}
            >
              <span className="lf-fchip-n">{counts[s.kind]}</span>
              <span className="lf-fchip-l">{s.label}</span>
            </button>
          ))}
        </div>

        <div className="c-input-row" style={{ margin: '0 16px 12px' }}>
          <Icons.Search size={18} />
          <input type="text" placeholder="Search by name or reference…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>

        {error && <div className="c-error-box" style={{ margin: '0 16px 12px' }}>{error}</div>}

        {loading ? (
          <Spinner label="Loading bookings…" />
        ) : filtered.length > 0 ? (
          filtered.map((b) => {
            const status = String(b.status || 'Booked');
            const name = b.customer?.name || b.customer_name || 'Guest';
            const services = b.services?.map((s) => s.title || s.name).filter(Boolean).join(', ') || 'Service';
            const day = dateOf(b);
            const isToday = day === todayISO;
            const dateLabel = !day ? 'TBD' : isToday ? 'Today' : day.slice(5).replace('-', '/');
            const when = b.start_time ? formatTime(b.start_time) : (b.show_date ?? '');
            return (
              <button key={b.id} className="c-up-row" onClick={() => navigate(`/booking/${b.id}`)}>
                <span className="c-up-time">
                  {dateLabel}
                  {when ? <em>{when}</em> : null}
                </span>
                <span className="c-up-body">
                  <span className="c-up-name">{name}</span>
                  <span className="c-up-sub">{services}</span>
                  {b.staff?.name ? <span className="c-up-sub"><Icons.User size={11} /> {b.staff.name}</span> : null}
                </span>
                <span className="c-up-end">
                  <span className={chipClass(status)}>{status}</span>
                  <span className="c-up-price">AED {b.charges ?? 0}</span>
                </span>
              </button>
            );
          })
        ) : (
          <EmptyState
            icon={<span className="c-empty-badge"><Icons.Calendar size={26} /></span>}
            title={search || filter !== 'All' ? 'No matching bookings' : 'No bookings yet'}
            subtitle={search || filter !== 'All' ? 'Try a different filter or search term.' : 'New bookings from your customers will show up here.'}
          />
        )}
      </div>
    </div>
  );
}
