import { useEffect, useMemo, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getInsights, type Insights as InsightsData, type InsightsDaily } from '@/lib/insights';
import '@/styles/insights.css';

/* ---------- date helpers ---------------------------------------------------- */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseISO = (s: string) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const daysBetween = (from: string, to: string) =>
  Math.round((parseISO(to).getTime() - parseISO(from).getTime()) / 86_400_000) + 1;
const fmtLong = (s: string) => { const d = parseISO(s); return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`; };
const fmtShort = (s: string) => { const d = parseISO(s); return `${d.getDate()} ${MONTHS[d.getMonth()]}`; };
const fmtNum = (n: number) => n.toLocaleString();

type PresetKey = '7d' | '30d' | '90d' | 'mtd' | 'lastmonth' | 'ytd' | 'custom';
const PRESETS: { key: Exclude<PresetKey, 'custom'>; label: string }[] = [
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '90d', label: '90 days' },
  { key: 'mtd', label: 'This month' },
  { key: 'lastmonth', label: 'Last month' },
  { key: 'ytd', label: 'This year' },
];

function presetRange(key: Exclude<PresetKey, 'custom'>, today: Date): { from: string; to: string } {
  const y = today.getFullYear();
  const m = today.getMonth();
  switch (key) {
    case '7d': return { from: iso(addDays(today, -6)), to: iso(today) };
    case '30d': return { from: iso(addDays(today, -29)), to: iso(today) };
    case '90d': return { from: iso(addDays(today, -89)), to: iso(today) };
    case 'mtd': return { from: iso(new Date(y, m, 1)), to: iso(today) };
    case 'lastmonth': return { from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) };
    case 'ytd': return { from: iso(new Date(y, 0, 1)), to: iso(today) };
  }
}

/* ---------- colour roles (theme tokens) ------------------------------------ */
const C = {
  completed: 'var(--mint-300)',
  booked: 'var(--info)',
  cancelled: 'var(--warn)',
  no_show: 'var(--danger)',
  returning: 'var(--mint-300)',
  new: 'var(--info)',
};

/* ---------- small presentational pieces ------------------------------------ */
function ChartCard({ icon, title, sub, span2, children }: {
  icon: keyof typeof Icons; title: string; sub: string; span2?: boolean; children: React.ReactNode;
}) {
  const Icon = Icons[icon];
  return (
    <div className={`ins-card${span2 ? ' span2' : ''}`}>
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icon size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">{title}</span>
          <span className="ins-card-sub">{sub}</span>
        </span>
      </div>
      {children}
    </div>
  );
}

function Delta({ change, display, goodDir }: { change: number | null; display: string; goodDir: 'up' | 'down' }) {
  if (change === null) return <span className="ins-kpi-delta flat"><span className="vs">no prior data</span></span>;
  const arrow = change > 0 ? '▲' : change < 0 ? '▼' : '—';
  const cls = change === 0 ? 'flat' : (change > 0) === (goodDir === 'up') ? 'up' : 'down';
  return <span className={`ins-kpi-delta ${cls}`}>{arrow} {display} <span className="vs">vs prev</span></span>;
}

function Kpi({ label, value, unit, delta }: {
  label: string; value: string; unit?: string; delta: React.ReactNode;
}) {
  return (
    <div className="ins-kpi">
      <span className="ins-kpi-label">{label}</span>
      <span className="ins-kpi-value">{value}{unit && <span className="u">{unit}</span>}</span>
      {delta}
    </div>
  );
}

function EmptyState({ text }: { text: string }) {
  return (
    <div className="ins-empty">
      <span className="ins-empty-ic"><Icons.Chart size={26} /></span>
      <span className="ins-empty-txt">{text}</span>
    </div>
  );
}

/* ---------- trend area chart (custom SVG + hover) -------------------------- */
type TrendPoint = { date: string; value: number };
function downsample(daily: InsightsDaily[], maxPoints = 45): { points: TrendPoint[]; bucketed: boolean } {
  if (daily.length <= maxPoints) return { points: daily.map((d) => ({ date: d.date, value: d.total })), bucketed: false };
  const size = Math.ceil(daily.length / maxPoints);
  const points: TrendPoint[] = [];
  for (let i = 0; i < daily.length; i += size) {
    const chunk = daily.slice(i, i + size);
    points.push({ date: chunk[0].date, value: chunk.reduce((s, c) => s + c.total, 0) });
  }
  return { points, bucketed: true };
}

function TrendChart({ daily }: { daily: InsightsDaily[] }) {
  const [hover, setHover] = useState<number | null>(null);
  const { points, bucketed } = useMemo(() => downsample(daily), [daily]);
  const total = points.reduce((s, p) => s + p.value, 0);
  if (total === 0) return <EmptyState text="No bookings in this range yet." />;

  const W = 600, H = 200, top = 18, bottom = 26;
  const plotH = H - top - bottom;
  const n = points.length;
  const maxY = Math.max(1, ...points.map((p) => p.value));
  const niceMax = Math.ceil(maxY / 4) * 4 || 4;
  const x = (i: number) => (n === 1 ? W / 2 : (i / (n - 1)) * W);
  const y = (v: number) => top + plotH * (1 - v / niceMax);

  const line = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' ');
  const area = `${line} L${x(n - 1).toFixed(1)},${top + plotH} L${x(0).toFixed(1)},${top + plotH} Z`;
  const gridVals = [0, niceMax / 2, niceMax];

  const onMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const r = e.currentTarget.getBoundingClientRect();
    const frac = (e.clientX - r.left) / r.width;
    setHover(Math.max(0, Math.min(n - 1, Math.round(frac * (n - 1)))));
  };

  const h = hover !== null ? points[hover] : null;

  return (
    <div className="ins-chartbox" onPointerMove={onMove} onPointerLeave={() => setHover(null)}>
      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" role="img" aria-label="Bookings over time">
        <defs>
          <linearGradient id="insTrend" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="var(--mint-300)" stopOpacity="0.34" />
            <stop offset="100%" stopColor="var(--mint-300)" stopOpacity="0.02" />
          </linearGradient>
        </defs>
        {gridVals.map((v, i) => (
          <g key={i}>
            <line className="ins-grid-line" x1={0} x2={W} y1={y(v)} y2={y(v)} />
            <text className="ins-axis-lab" x={2} y={y(v) - 3}>{Math.round(v)}</text>
          </g>
        ))}
        <path d={area} fill="url(#insTrend)" />
        <path d={line} fill="none" stroke="var(--mint-300)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        {h && (
          <g>
            <line className="ins-grid-line" x1={x(hover!)} x2={x(hover!)} y1={top} y2={top + plotH} stroke="var(--border-3)" />
            <circle cx={x(hover!)} cy={y(h.value)} r={4} fill="var(--mint-300)" stroke="var(--bg-1)" strokeWidth={2} vectorEffect="non-scaling-stroke" />
          </g>
        )}
        {[0, Math.floor((n - 1) / 2), n - 1].filter((v, i, a) => a.indexOf(v) === i && v >= 0).map((i) => (
          <text key={i} className="ins-axis-lab" x={Math.max(12, Math.min(W - 12, x(i)))} y={H - 8}
            textAnchor={i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}>{fmtShort(points[i].date)}</text>
        ))}
      </svg>
      {h && (
        <div className="ins-tooltip" style={{ left: `${(x(hover!) / W) * 100}%`, top: `${(y(h.value) / H) * 100}%` }}>
          <div className="ins-tt-date">{bucketed ? `Week of ${fmtShort(h.date)}` : fmtLong(h.date)}</div>
          <div className="ins-tt-val"><span className="d">{fmtNum(h.value)}</span> booking{h.value === 1 ? '' : 's'}</div>
        </div>
      )}
    </div>
  );
}

/* ---------- donut ---------------------------------------------------------- */
type Seg = { key: string; label: string; value: number; color: string };
function Donut({ segments, cap }: { segments: Seg[]; cap: string }) {
  const total = segments.reduce((s, x) => s + x.value, 0);
  const r = 52, cx = 66, cy = 66, sw = 16, Circ = 2 * Math.PI * r;
  if (total === 0) return <EmptyState text="No bookings to break down yet." />;
  let offset = 0;
  return (
    <div className="ins-donut-row">
      <div className="ins-donut" aria-hidden="true">
        <svg viewBox="0 0 132 132">
          <circle cx={cx} cy={cy} r={r} fill="none" stroke="var(--neutral-soft)" strokeWidth={sw} />
          {segments.filter((s) => s.value > 0).map((s) => {
            const dash = (s.value / total) * Circ;
            const el = (
              <circle key={s.key} className="ins-donut-seg" cx={cx} cy={cy} r={r} fill="none"
                stroke={s.color} strokeWidth={sw} strokeLinecap="butt"
                strokeDasharray={`${Math.max(dash - 2, 0.001)} ${Circ}`} strokeDashoffset={-offset}>
                <title>{`${s.label}: ${fmtNum(s.value)} (${Math.round((s.value / total) * 100)}%)`}</title>
              </circle>
            );
            offset += dash;
            return el;
          })}
        </svg>
        <div className="ins-donut-center">
          <span className="ins-donut-total">{fmtNum(total)}</span>
          <span className="ins-donut-cap">{cap}</span>
        </div>
      </div>
      <div className="ins-legend">
        {segments.map((s) => (
          <div key={s.key} className="ins-legend-item">
            <span className="ins-legend-dot" style={{ background: s.color }} />
            <span className="ins-legend-lab">{s.label}</span>
            <span className="ins-legend-val">{fmtNum(s.value)}</span>
            <span className="ins-legend-pct">{total ? Math.round((s.value / total) * 100) : 0}%</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ---------- rate bars ------------------------------------------------------ */
function RateBars({ rows }: { rows: { label: string; value: number; color: string }[] }) {
  return (
    <div className="ins-rates">
      {rows.map((r) => (
        <div key={r.label}>
          <div className="ins-rate-head">
            <span className="ins-rate-lab">{r.label}</span>
            <span className="ins-rate-val">{r.value}%</span>
          </div>
          <div className="ins-rate-track">
            <div className="ins-rate-fill" style={{ width: `${Math.max(0, Math.min(100, r.value))}%`, background: r.color }} />
          </div>
        </div>
      ))}
    </div>
  );
}

/* ---------- reviews -------------------------------------------------------- */
function Reviews({ average, count }: { average: number | null; count: number }) {
  const rounded = average != null ? Math.round(average) : 0;
  return (
    <div className="ins-reviews">
      <div className="ins-rev-big">
        <span className="ins-rev-num">{average != null ? average.toFixed(1) : '—'}</span>
        <span className="ins-rev-stars">
          {[1, 2, 3, 4, 5].map((i) => <span key={i} className={`ins-rev-star${i <= rounded ? ' on' : ''}`}>★</span>)}
        </span>
      </div>
      <div className="ins-rev-meta">
        {count > 0
          ? <span className="ins-rev-count"><b>{fmtNum(count)}</b> review{count === 1 ? '' : 's'} in this range</span>
          : <span className="ins-rev-count">No reviews yet</span>}
        <span className="ins-rev-hint">Ratings collected after completed visits.</span>
      </div>
    </div>
  );
}

/* ---------- loading skeleton ----------------------------------------------- */
function Skeleton() {
  return (
    <>
      <div className="ins-kpis">{[0, 1, 2, 3].map((i) => <div key={i} className="ins-skel" style={{ height: 92 }} />)}</div>
      <div className="ins-skel" style={{ height: 240 }} />
      <div className="ins-grid">
        <div className="ins-skel" style={{ height: 200 }} />
        <div className="ins-skel" style={{ height: 200 }} />
      </div>
    </>
  );
}

/* ---------- page ----------------------------------------------------------- */
const pctChange = (cur: number, prev: number): number | null => {
  if (prev === 0) return cur === 0 ? 0 : null;
  return ((cur - prev) / prev) * 100;
};

export default function Insights() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const today = useMemo(() => new Date(), []);

  const [preset, setPreset] = useState<PresetKey>('30d');
  const initial = useMemo(() => presetRange('30d', today), [today]);
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);

  const [data, setData] = useState<InsightsData | null>(null);
  const [prev, setPrev] = useState<InsightsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const choosePreset = (key: Exclude<PresetKey, 'custom'>) => {
    const r = presetRange(key, today);
    setPreset(key); setFrom(r.from); setTo(r.to);
  };

  // Normalised, so an inverted custom range still behaves.
  const nf = from <= to ? from : to;
  const nt = from <= to ? to : from;

  const fetchData = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true); setError('');
    const len = daysBetween(nf, nt);
    const pTo = iso(addDays(parseISO(nf), -1));
    const pFrom = iso(addDays(parseISO(pTo), -(len - 1)));
    try {
      const [cur, previous] = await Promise.allSettled([
        getInsights(shop.id, nf, nt),
        getInsights(shop.id, pFrom, pTo),
      ]);
      if (cur.status === 'rejected') throw cur.reason;
      setData(cur.value);
      setPrev(previous.status === 'fulfilled' ? previous.value : null);
    } catch {
      setError('Could not load insights.');
      setData(null); setPrev(null);
    } finally {
      setLoading(false);
    }
  }, [shop?.id, nf, nt]);

  useEffect(() => { void fetchData(); }, [fetchData]);

  const rangeLen = daysBetween(nf, nt);

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate('/overview')}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Insights</h1>
        <p className="c-page-sub">A full report on your bookings, customers & quality.</p>
      </div>

      <div className="ins-wrap">
        {/* Advanced filter */}
        <div className="ins-filter">
          <div className="ins-seg" role="group" aria-label="Quick ranges">
            {PRESETS.map((p) => (
              <button key={p.key} className={`ins-seg-btn${preset === p.key ? ' on' : ''}`}
                aria-pressed={preset === p.key} onClick={() => choosePreset(p.key)}>{p.label}</button>
            ))}
          </div>
          <div className="ins-range-row">
            <span className="ins-range-lab">Custom</span>
            <input className="ins-date-input" type="date" value={from} max={to}
              onChange={(e) => { setFrom(e.target.value); setPreset('custom'); }} aria-label="From date" />
            <span className="ins-date-dash">→</span>
            <input className="ins-date-input" type="date" value={to} min={from} max={iso(today)}
              onChange={(e) => { setTo(e.target.value); setPreset('custom'); }} aria-label="To date" />
            <span className="ins-active"><b>{fmtLong(nf)}</b> – <b>{fmtLong(nt)}</b> · {rangeLen} day{rangeLen === 1 ? '' : 's'}</span>
          </div>
        </div>

        {error && <div className="c-error-box">{error}</div>}

        {loading ? <Skeleton /> : data ? (
          <>
            {/* KPI hero row */}
            <div className="ins-kpis">
              <Kpi label="Total bookings" value={fmtNum(data.bookings.scheduled)}
                delta={<Delta change={prev ? pctChange(data.bookings.scheduled, prev.bookings.scheduled) : null}
                  display={prev ? `${Math.abs(Math.round(pctChange(data.bookings.scheduled, prev.bookings.scheduled) ?? 0))}%` : ''} goodDir="up" />} />
              <Kpi label="Completion" value={String(data.rates.completion)} unit="%"
                delta={<Delta change={prev ? data.rates.completion - prev.rates.completion : null}
                  display={prev ? `${Math.abs(+(data.rates.completion - prev.rates.completion).toFixed(1))} pts` : ''} goodDir="up" />} />
              <Kpi label="No-show rate" value={String(data.rates.no_show)} unit="%"
                delta={<Delta change={prev ? data.rates.no_show - prev.rates.no_show : null}
                  display={prev ? `${Math.abs(+(data.rates.no_show - prev.rates.no_show).toFixed(1))} pts` : ''} goodDir="down" />} />
              <Kpi label="Avg rating" value={data.reviews.average != null ? data.reviews.average.toFixed(1) : '—'}
                delta={<Delta
                  change={prev && prev.reviews.average != null && data.reviews.average != null ? data.reviews.average - prev.reviews.average : null}
                  display={prev && prev.reviews.average != null && data.reviews.average != null ? Math.abs(+(data.reviews.average - prev.reviews.average).toFixed(1)).toFixed(1) : ''} goodDir="up" />} />
            </div>

            {/* Trend — full width */}
            <ChartCard icon="Chart" title="Bookings over time" sub={rangeLen > 62 ? 'Weekly totals' : 'Daily totals'} span2>
              <TrendChart daily={data.daily} />
            </ChartCard>

            <div className="ins-grid">
              <ChartCard icon="Calendar" title="Outcomes" sub="How scheduled bookings ended">
                <Donut cap="Scheduled" segments={[
                  { key: 'completed', label: 'Completed', value: data.bookings.completed, color: C.completed },
                  { key: 'booked', label: 'Upcoming', value: data.bookings.booked, color: C.booked },
                  { key: 'cancelled', label: 'Cancelled', value: data.bookings.cancelled, color: C.cancelled },
                  { key: 'no_show', label: 'No-show', value: data.bookings.no_show, color: C.no_show },
                ]} />
              </ChartCard>

              <ChartCard icon="Check" title="Rates" sub="Share of scheduled bookings">
                <RateBars rows={[
                  { label: 'Completion', value: data.rates.completion, color: C.completed },
                  { label: 'Cancellation', value: data.rates.cancellation, color: C.cancelled },
                  { label: 'No-show', value: data.rates.no_show, color: C.no_show },
                ]} />
              </ChartCard>

              <ChartCard icon="Users" title="Customers" sub={`${data.customers.repeat_rate}% repeat rate`}>
                <Donut cap="Customers" segments={[
                  { key: 'returning', label: 'Returning', value: data.customers.returning, color: C.returning },
                  { key: 'new', label: 'New', value: data.customers.new, color: C.new },
                ]} />
              </ChartCard>

              <ChartCard icon="Heart" title="Reviews" sub="Customer satisfaction">
                <Reviews average={data.reviews.average} count={data.reviews.count} />
              </ChartCard>
            </div>
          </>
        ) : null}
      </div>
    </div></div>
  );
}
