import { useEffect, useMemo, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { ChartCard } from '@/components/charts/ChartCard';
import { Donut } from '@/components/charts/Donut';
import { Kpi, Delta } from '@/components/charts/Kpi';
import { RangeFilter } from '@/components/charts/RangeFilter';
import { RateBars } from '@/components/charts/RateBars';
import { TrendChart } from '@/components/charts/TrendChart';
import { useShop } from '@/context/ShopContext';
import { getInsights, type Insights as InsightsData } from '@/lib/insights';
import {
  daysBetween, fmtNum, pctChange, presetRange, previousRange, type PresetKey,
} from '@/lib/dateRange';
import '@/styles/insights.css';

/* ---------- colour roles (theme tokens) ------------------------------------ */
const C = {
  completed: 'var(--mint-300)',
  booked: 'var(--info)',
  cancelled: 'var(--warn)',
  no_show: 'var(--danger)',
  returning: 'var(--mint-300)',
  new: 'var(--info)',
};

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
    const p = previousRange(nf, nt);
    try {
      const [cur, previous] = await Promise.allSettled([
        getInsights(shop.id, nf, nt),
        getInsights(shop.id, p.from, p.to),
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
      <button className="c-back" onClick={() => navigate('/settings')}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Insights</h1>
        <p className="c-page-sub">A full report on your bookings, customers & quality.</p>
      </div>

      <div className="ins-wrap">
        <RangeFilter
          preset={preset} from={from} to={to}
          onPreset={choosePreset}
          onFrom={(v) => { setFrom(v); setPreset('custom'); }}
          onTo={(v) => { setTo(v); setPreset('custom'); }}
        />

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
              <TrendChart
                emptyText="No bookings in this range yet."
                series={[{
                  key: 'bookings',
                  label: 'bookings',
                  color: 'var(--mint-300)',
                  points: data.daily.map((d) => ({ date: d.date, value: d.total })),
                }]}
              />
            </ChartCard>

            <div className="ins-grid">
              <ChartCard icon="Calendar" title="Outcomes" sub="How scheduled bookings ended">
                <Donut cap="Scheduled" emptyText="No bookings to break down yet." segments={[
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
                <Donut cap="Customers" emptyText="No bookings to break down yet." segments={[
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
