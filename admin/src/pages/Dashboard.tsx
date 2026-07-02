import { useEffect, useState, useCallback } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getShopBookings } from '@/lib/bookings';
import { getWaAccount } from '@/lib/chats';
import { storage } from '@/lib/storage';
import { formatLocalDate } from '@/lib/date';
import type { Booking } from '@/types';

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

const QUICK_ACTIONS: { label: string; to: string; icon: keyof typeof Icons }[] = [
  { label: 'Bookings', to: '/bookings', icon: 'Calendar' },
  { label: 'Chats', to: '/chats', icon: 'Chat' },
  { label: 'Staff', to: '/staff', icon: 'Users' },
  { label: 'Services', to: '/services', icon: 'Grid' },
  { label: 'Hours', to: '/working-hours', icon: 'Clock' },
  { label: 'Profile', to: '/profile', icon: 'Store' },
];

export default function Dashboard() {
  const navigate = useNavigate();
  const { shop, logoutShop } = useShop();
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [totalBookings, setTotalBookings] = useState<number | null>(null);
  const [totalRevenue, setTotalRevenue] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  // Master accounts skip the provider experience entirely.
  // Onboarding chain for regular shops: 1) lock in a category once,
  // 2) then a one-time WhatsApp setup nudge.
  useEffect(() => {
    if (!shop?.id) return;
    if (shop.is_master) {
      navigate('/master', { replace: true });
      return;
    }
    if (!shop.category_confirmed_at) {
      navigate('/category-setup');
      return;
    }
    // Per-shop so the nudge is judged for THIS shop, not once per device —
    // otherwise a second shop on the same device would never be prompted.
    const waPromptKey = `wa_setup_prompted:${shop.id}`;
    if (storage.get(waPromptKey)) return;
    let alive = true;
    getWaAccount()
      .then((acc) => {
        if (!alive) return;
        storage.set(waPromptKey, '1');
        if (!acc.connected) navigate('/chats/setup');
      })
      .catch(() => undefined);
    return () => { alive = false; };
  }, [shop?.id, shop?.category_confirmed_at, navigate]);

  const fetchData = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true);
    setError('');
    try {
      const res = await getShopBookings(shop.id);
      const list = res.data;
      const rev = list.reduce((s, b) => s + Number(b.charges ?? 0), 0);
      setBookings(list);
      setTotalBookings(res.total_bookings ?? list.length);
      setTotalRevenue(res.total_revenue != null ? Number(res.total_revenue) : rev);
    } catch (e: unknown) {
      if ((e as { response?: { status?: number } })?.response?.status === 401) { logoutShop(); navigate('/login'); return; }
      setError('Could not load bookings.');
      setBookings([]);
      setTotalBookings(0);
      setTotalRevenue(0);
    } finally {
      setLoading(false);
    }
  }, [shop?.id, logoutShop, navigate]);

  useEffect(() => { void fetchData(); }, [fetchData]);

  const todayISO = formatLocalDate(new Date());
  const todayCount = bookings.filter((b) => dateOf(b) === todayISO).length;
  const completedCount = bookings.filter((b) => String(b.status).toLowerCase() === 'completed').length;
  const upcoming = bookings
    .filter((b) => dateOf(b) >= todayISO && String(b.status).toLowerCase() !== 'cancelled')
    .sort((a, b) => (dateOf(a) === dateOf(b)
      ? (a.start_time ?? '').localeCompare(b.start_time ?? '')
      : dateOf(a).localeCompare(dateOf(b))))
    .slice(0, 5);

  return (
    <div className="m-screen c-dash">
      <div className="m-scroll">
        {/* Greeting header */}
        <div className="c-dash-head">
          <div className="c-dash-orb">
            {shop?.logo
              ? <img src={shop.logo} alt="" />
              : (Array.from(shop?.name || '?')[0] || '?').toUpperCase()}
          </div>
          <div className="c-dash-head-text">
            <div className="c-dash-name">{shop?.name ?? 'Dashboard'}</div>
            <div className="c-dash-status">
              <span className={`c-live-dot${shop?.is_open ? '' : ' off'}`} />
              {shop?.is_open ? 'Open now' : 'Closed'}
            </div>
          </div>
          <button className="c-icon-btn" aria-label="Log out" onClick={() => { logoutShop(); navigate('/login'); }}>
            <Icons.Logout size={18} />
          </button>
        </div>

        {error && <div className="c-error-box" style={{ margin: '0 16px 12px' }}>{error}</div>}

        {/* Hero revenue card */}
        <div className="c-hero-stat">
          <div className="c-hero-label">Total revenue</div>
          <div className="c-hero-value">{totalRevenue != null ? `AED ${totalRevenue.toLocaleString()}` : '—'}</div>
          <div className="c-hero-foot">{totalBookings ?? '—'} bookings all time</div>
        </div>

        {/* Mini stats */}
        <div className="c-mini-stats">
          <div className="c-mini"><span className="v">{todayCount}</span><span className="k">Today</span></div>
          <div className="c-mini"><span className="v">{completedCount}</span><span className="k">Completed</span></div>
          <div className="c-mini"><span className="v">{totalBookings ?? '—'}</span><span className="k">Bookings</span></div>
        </div>

        {/* Quick actions */}
        <div className="c-section-title">Quick actions</div>
        <div className="c-qa-grid">
          {QUICK_ACTIONS.map((a) => {
            const Icon = Icons[a.icon];
            return (
              <Link key={a.to} to={a.to} className="c-qa-tile">
                <span className="c-qa-ic"><Icon size={18} /></span>
                <span>{a.label}</span>
              </Link>
            );
          })}
        </div>

        <div className="c-section-title">Upcoming bookings</div>
        {loading ? (
          <Spinner label="Loading bookings…" />
        ) : upcoming.length > 0 ? (
          upcoming.map((b) => {
            const name = b.customer?.name || b.customer_name || 'Guest';
            const services = b.services?.map((s) => s.title || s.name).filter(Boolean).join(', ') || 'Service';
            const isToday = dateOf(b) === todayISO;
            const when = b.start_time ? formatTime(b.start_time) : (b.show_date ?? 'TBD');
            return (
              <button key={b.id} className="c-up-row" onClick={() => navigate(`/booking/${b.id}`)}>
                <span className="c-up-time">
                  {isToday ? 'Today' : dateOf(b).slice(5).replace('-', '/')}
                  <em>{when}</em>
                </span>
                <span className="c-up-body">
                  <span className="c-up-name">{name}</span>
                  <span className="c-up-sub">{services}</span>
                </span>
                <span className="c-up-price">AED {b.charges ?? 0}</span>
              </button>
            );
          })
        ) : (
          <EmptyState title="No upcoming bookings" subtitle="New bookings will appear here." />
        )}
      </div>
    </div>
  );
}
