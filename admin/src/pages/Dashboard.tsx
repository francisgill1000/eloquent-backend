import { useEffect, useState, useCallback } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { QRCodeSVG } from 'qrcode.react';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getShopBookings } from '@/lib/bookings';
import { getCustomerCount } from '@/lib/customers';
import { listCatalogs } from '@/lib/catalogs';
import { getStaff, getShop } from '@/lib/shops';
import { getWaAccount } from '@/lib/chats';
import { WHATSAPP_ENABLED } from '@/lib/features';
import { storage } from '@/lib/storage';
import { formatLocalDate } from '@/lib/date';
import type { Booking } from '@/types';

// Where the public booking page lives (matches the Profile page's QR target).
const CUSTOMER_WEB = 'https://bookings.eloquentservice.com';

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

const ALL_QUICK_ACTIONS: { label: string; to: string; icon: keyof typeof Icons; sub: string }[] = [
  { label: 'Bookings', to: '/bookings', icon: 'Calendar', sub: 'View & manage bookings' },
  // Chats (WhatsApp) — hidden temporarily behind WHATSAPP_ENABLED.
  { label: 'Chats', to: '/chats', icon: 'Chat', sub: 'Reply to customers' },
  { label: 'Staff', to: '/staff', icon: 'Users', sub: 'Manage your team' },
  { label: 'Services', to: '/services', icon: 'Grid', sub: 'Edit what you offer' },
  { label: 'Hours', to: '/working-hours', icon: 'Clock', sub: 'Set open & close times' },
  { label: 'Profile', to: '/profile', icon: 'Store', sub: 'Details & booking QR' },
];
const QUICK_ACTIONS = ALL_QUICK_ACTIONS.filter((a) => WHATSAPP_ENABLED || a.to !== '/chats');

// Onboarding flow. Working hours is pre-populated by default (so it reads as
// already done) — put it first so the stepper progresses cleanly left-to-right
// instead of showing a completed step ahead of an unfinished one.
type SetupKey = 'services' | 'hours' | 'staff';
const SETUP_STEPS: { key: SetupKey; label: string; sub: string; to: string }[] = [
  { key: 'hours', label: 'Set working hours', sub: 'Your open & close times', to: '/working-hours' },
  { key: 'services', label: 'Add services', sub: 'List what customers can book', to: '/services' },
  { key: 'staff', label: 'Add staff', sub: 'Assign who handles bookings', to: '/staff' },
];

// Unique customers per month for the last N months, derived from the bookings.
function customersByMonth(bookings: Booking[], months = 6): { label: string; value: number }[] {
  const now = new Date();
  const buckets = Array.from({ length: months }, (_, i) => {
    const dt = new Date(now.getFullYear(), now.getMonth() - (months - 1 - i), 1);
    return { key: `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}`, label: dt.toLocaleString('en-US', { month: 'short' }), set: new Set<string>() };
  });
  const byKey = new Map(buckets.map((b) => [b.key, b]));
  for (const b of bookings) {
    const bucket = byKey.get(dateOf(b).slice(0, 7));
    if (bucket) bucket.set.add(String(b.customer?.phone ?? b.customer_whatsapp ?? b.customer?.name ?? b.customer_name ?? b.id));
  }
  return buckets.map((b) => ({ label: b.label, value: b.set.size }));
}

// Tiny CSS bar chart used in the Customers card.
function MiniBars({ data }: { data: { label: string; value: number }[] }) {
  const max = Math.max(1, ...data.map((d) => d.value));
  return (
    <div className="c-dash-chart">
      {data.map((d, i) => (
        <div className="c-dash-bar-col" key={i}>
          <span className="c-dash-bar-val">{d.value || ''}</span>
          <div className="c-dash-bar" style={{ height: `${(d.value / max) * 100}%` }} />
          <span className="c-dash-bar-label">{d.label}</span>
        </div>
      ))}
    </div>
  );
}

// Business Overview (desktop) time filter — recomputes the KPI cards below.
type PeriodKey = 'today' | 'month' | 'year';
const PERIODS: { key: PeriodKey; label: string }[] = [
  { key: 'today', label: 'Today' },
  { key: 'month', label: 'This month' },
  { key: 'year', label: 'Year' },
];

// Compact AED formatter for the overview cards (e.g. "AED 486k").
function aed(n: number): string {
  if (n >= 1000) {
    const k = n / 1000;
    return `AED ${(k >= 100 ? Math.round(k) : Math.round(k * 10) / 10).toLocaleString()}k`;
  }
  return `AED ${Math.round(n).toLocaleString()}`;
}

// Period-scoped stats derived from the loaded bookings (cancelled excluded
// from revenue/count so the figures reflect real earned business).
function periodStats(list: Booking[], pred: (b: Booking) => boolean) {
  const rows = list.filter(pred);
  const active = rows.filter((b) => String(b.status).toLowerCase() !== 'cancelled');
  const revenue = active.reduce((s, b) => s + Number(b.charges ?? 0), 0);
  const count = active.length;
  const completed = rows.filter((b) => String(b.status).toLowerCase() === 'completed').length;
  return { revenue, count, completed, avg: count ? revenue / count : 0 };
}

export default function Dashboard() {
  const navigate = useNavigate();
  const { shop, logoutShop } = useShop();
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [totalBookings, setTotalBookings] = useState<number | null>(null);
  const [totalRevenue, setTotalRevenue] = useState<number | null>(null);
  const [customerCount, setCustomerCount] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [period, setPeriod] = useState<PeriodKey>('month');
  const [setupState, setSetupState] = useState<Record<SetupKey, boolean> | null>(null);

  const qrTarget = shop?.id ? `${CUSTOMER_WEB}/shop/${shop.id}` : '';

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
    // WhatsApp setup nudge — suppressed while the feature is hidden.
    if (!WHATSAPP_ENABLED) return;
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

  // Customer count for the dashboard card (desktop right column).
  useEffect(() => {
    if (!shop?.id) return;
    let alive = true;
    getCustomerCount(shop.id)
      .then((n) => { if (alive) setCustomerCount(n); })
      .catch(() => undefined);
    return () => { alive = false; };
  }, [shop?.id]);

  // Setup progress — services / working hours / staff. Login omits working_hours,
  // so fetch the full shop; each source is independent, so failures degrade to "not done".
  useEffect(() => {
    if (!shop?.id) return;
    let alive = true;
    Promise.allSettled([listCatalogs(), getShop(shop.id), getStaff(shop.id)])
      .then(([svc, full, staff]) => {
        if (!alive) return;
        const hasServices = svc.status === 'fulfilled' && svc.value.length > 0;
        const hasHours = full.status === 'fulfilled' && (full.value.working_hours?.length ?? 0) > 0;
        const hasStaff = staff.status === 'fulfilled' && staff.value.length > 0;
        setSetupState({ services: hasServices, hours: hasHours, staff: hasStaff });
      });
    return () => { alive = false; };
  }, [shop?.id]);

  const todayISO = formatLocalDate(new Date());
  const todayCount = bookings.filter((b) => dateOf(b) === todayISO).length;
  const completedCount = bookings.filter((b) => String(b.status).toLowerCase() === 'completed').length;
  const upcoming = bookings
    .filter((b) => dateOf(b) >= todayISO && String(b.status).toLowerCase() !== 'cancelled')
    .sort((a, b) => (dateOf(a) === dateOf(b)
      ? (a.start_time ?? '').localeCompare(b.start_time ?? '')
      : dateOf(a).localeCompare(dateOf(b))))
    .slice(0, 5);

  // Business Overview KPIs (desktop): period-scoped values + real
  // period-over-period deltas, all computed from the loaded bookings.
  const now = new Date();
  const ymNow = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  const prevM = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  const ymPrev = `${prevM.getFullYear()}-${String(prevM.getMonth() + 1).padStart(2, '0')}`;
  const yNow = String(now.getFullYear());
  const yPrev = String(now.getFullYear() - 1);
  const yesterdayISO = formatLocalDate(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1));
  const curPred = period === 'today'
    ? (b: Booking) => dateOf(b) === todayISO
    : period === 'month'
      ? (b: Booking) => dateOf(b).startsWith(ymNow)
      : (b: Booking) => dateOf(b).startsWith(yNow);
  const prevPred = period === 'today'
    ? (b: Booking) => dateOf(b) === yesterdayISO
    : period === 'month'
      ? (b: Booking) => dateOf(b).startsWith(ymPrev)
      : (b: Booking) => dateOf(b).startsWith(yPrev);
  const cur = periodStats(bookings, curPred);
  const prev = periodStats(bookings, prevPred);
  const pct = (c: number, p: number) => (p > 0 ? Math.round(((c - p) / p) * 100) : null);
  const suffix = period === 'today' ? 'Today' : period === 'month' ? 'MTD' : 'YTD';
  const vsWord = period === 'today' ? 'yesterday' : period === 'month' ? 'last month' : 'last year';
  const ovCards: { key: string; label: string; value: string; accent?: boolean; trend: number | null; sub?: string }[] = [
    { key: 'rev', label: `Revenue · ${suffix}`, value: aed(cur.revenue), accent: true, trend: pct(cur.revenue, prev.revenue) },
    { key: 'bk', label: `Bookings · ${suffix}`, value: cur.count.toLocaleString(), trend: pct(cur.count, prev.count) },
    { key: 'done', label: `Completed · ${suffix}`, value: cur.completed.toLocaleString(), trend: pct(cur.completed, prev.completed) },
    { key: 'cust', label: 'Customers', value: (customerCount ?? 0).toLocaleString(), trend: null, sub: 'All-time total' },
  ];

  // Desktop "Get started" empty state → setup stepper.
  const steps = SETUP_STEPS.map((s) => ({ ...s, done: !!setupState?.[s.key] }));
  const doneCount = steps.filter((s) => s.done).length;
  const currentIndex = steps.findIndex((s) => !s.done); // -1 once everything is done
  const showSetup = setupState !== null && currentIndex !== -1;

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

        {/* Setup alert — slim banner at the top on desktop; auto-hides once done. */}
        {showSetup && (
          <section className="c-setup-alert c-only-desktop">
            <span className="c-setup-alert-badge">{doneCount}/{steps.length}</span>
            <div className="c-setup-alert-text">
              <strong>Finish setting up your business</strong>
              <span>Next: {steps[currentIndex].label}</span>
            </div>
            <Link to={steps[currentIndex].to} className="c-setup-cta">Continue<Icons.ArrowRight size={16} /></Link>
          </section>
        )}

        {/* Desktop-only Business Overview header (mobile uses the greeting header above) */}
        <div className="c-dash-deskhead">
          <div className="c-dash-deskhead-text">
            <h1 className="c-dash-deskhead-title">Business Overview</h1>
            <p className="c-dash-deskhead-sub">
              <span className={`c-live-dot${shop?.is_open ? '' : ' off'}`} />
              {shop?.is_open ? 'Open now' : 'Closed'} · {shop?.name ?? 'your business'}
            </p>
          </div>
          <div className="c-ov-controls">
            <div className="c-seg" role="tablist" aria-label="Time range">
              {PERIODS.map((p) => (
                <button
                  key={p.key}
                  type="button"
                  role="tab"
                  aria-selected={period === p.key}
                  className={`c-seg-btn${period === p.key ? ' on' : ''}`}
                  onClick={() => setPeriod(p.key)}
                >
                  {p.label}
                </button>
              ))}
            </div>
          </div>
        </div>

        {error && <div className="c-error-box" style={{ margin: '0 16px 12px' }}>{error}</div>}

        {/* Business Overview KPI cards — desktop only (mobile keeps the KPI row below) */}
        <div className="c-ov">
          {ovCards.map((c) => (
            <div key={c.key} className={`c-ov-card${c.accent ? ' accent' : ''}`}>
              <div className="c-ov-label">{c.label}</div>
              <div className="c-ov-value">{c.value}</div>
              {c.sub ? (
                <div className="c-ov-trend flat">{c.sub}</div>
              ) : c.trend != null ? (
                <div className={`c-ov-trend ${c.trend >= 0 ? 'up' : 'down'}`}>
                  <span className="c-ov-arrow">{c.trend >= 0 ? '▲' : '▼'}</span>
                  {Math.abs(c.trend)}% vs {vsWord}
                </div>
              ) : (
                <div className="c-ov-trend flat">— vs {vsWord}</div>
              )}
            </div>
          ))}
        </div>

        {/* KPI row: revenue + stats. Desktop → one row; mobile → hero + 3-col stats (unchanged). */}
        <div className="c-dash-kpis">
          <div className="c-hero-stat">
            <div className="c-hero-label">Total revenue</div>
            <div className="c-hero-value">{totalRevenue != null ? `AED ${totalRevenue.toLocaleString()}` : '—'}</div>
            <div className="c-hero-foot">{totalBookings ?? '—'} bookings all time</div>
          </div>
          <div className="c-mini-stats">
            <div className="c-mini"><span className="v">{todayCount}</span><span className="k">Today</span></div>
            <div className="c-mini"><span className="v">{completedCount}</span><span className="k">Completed</span></div>
            <div className="c-mini"><span className="v">{totalBookings ?? '—'}</span><span className="k">Bookings</span></div>
          </div>
        </div>

        {/* Lower body. DOM order = Quick actions then Upcoming (matches mobile);
            desktop reorders Upcoming into the wide left panel via CSS. */}
        <div className={`c-dash-lower${!loading && upcoming.length === 0 ? ' is-empty' : ''}`}>
          <div className="c-dash-side">
            <div className="c-section-title">Quick actions</div>
            <div className="c-qa-grid">
              {QUICK_ACTIONS.map((a) => {
                const Icon = Icons[a.icon];
                return (
                  <Link key={a.to} to={a.to} className="c-qa-tile">
                    <span className="c-qa-ic"><Icon size={18} /></span>
                    <span className="c-qa-text">
                      <span className="c-qa-label">{a.label}</span>
                      <span className="c-qa-sub">{a.sub}</span>
                    </span>
                  </Link>
                );
              })}
            </div>
          </div>

          <div className="c-dash-main">
            {loading ? (
              <>
                <div className="c-section-title">Upcoming bookings</div>
                <Spinner label="Loading bookings…" />
              </>
            ) : upcoming.length === 0 ? (
              <>
                {/* Mobile keeps the simple empty state (unchanged) */}
                <div className="c-section-title c-only-mobile">Upcoming bookings</div>
                <div className="c-only-mobile"><EmptyState title="No upcoming bookings" subtitle="New bookings will appear here." /></div>
                {/* Desktop empty state. Setup guidance now lives in the top alert;
                    Customers + Booking QR live in the right column (c-dash-extra). */}
                <div className="c-only-desktop">
                  <div className="c-section-title">Upcoming bookings</div>
                  <EmptyState title="No upcoming bookings" subtitle="New bookings will appear here." />
                </div>
              </>
            ) : (
              <>
                <div className="c-section-title">Upcoming bookings</div>
                {upcoming.map((b) => {
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
                })}
              </>
            )}
          </div>

          {/* Desktop-only cards: customers chart + booking QR */}
          <div className="c-dash-extra">
            <div className="c-dash-chart-card">
              <div className="c-dash-chart-head">
                <span className="c-dash-chart-title">Customers</span>
                <span className="c-dash-chart-sub">Last 6 months</span>
              </div>
              <MiniBars data={customersByMonth(bookings)} />
            </div>
            {qrTarget && (
              <div className="c-dash-qr-card">
                <div className="c-qr-frame">
                  <QRCodeSVG value={qrTarget} size={150} level="M" bgColor="#ffffff" fgColor="#0a0e0c" />
                </div>
                <div className="c-dash-qr-cap">
                  <span className="c-dash-qr-title">Booking QR</span>
                  <span className="c-dash-qr-hint">Customers scan to book</span>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
