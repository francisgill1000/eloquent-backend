import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getInsights, type Insights as InsightsData } from '@/lib/insights';

function isoDaysAgo(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
}

function Tile({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="c-set-link" style={{ flex: '1 1 130px', flexDirection: 'column', alignItems: 'flex-start' }}>
      <span className="c-set-sub">{label}</span>
      <span className="c-page-title" style={{ fontSize: 26, color: tone }}>{value}</span>
    </div>
  );
}

const RANGES = [
  { label: '7 days', days: 7 },
  { label: '30 days', days: 30 },
  { label: '90 days', days: 90 },
];

export default function Insights() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const [days, setDays] = useState(30);
  const [data, setData] = useState<InsightsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const fetch = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true);
    setError('');
    try {
      setData(await getInsights(shop.id, isoDaysAgo(days), isoDaysAgo(0)));
    } catch {
      setError('Could not load insights.');
    } finally {
      setLoading(false);
    }
  }, [shop?.id, days]);

  useEffect(() => { void fetch(); }, [fetch]);

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate('/overview')}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Insights</h1>
        <p className="c-page-sub">Retention & quality metrics for your bookings.</p>
      </div>

      <div className="c-viewtog" role="group" aria-label="Range" style={{ marginBottom: 14 }}>
        {RANGES.map((r) => (
          <button key={r.days} className={`c-viewbtn${days === r.days ? ' on' : ''}`} aria-pressed={days === r.days}
            onClick={() => setDays(r.days)}>{r.label}</button>
        ))}
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {loading ? (
        <Spinner label="Loading insights…" />
      ) : data ? (
        <>
          <h3 className="c-set-sub" style={{ margin: '4px 0 8px' }}>Bookings</h3>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 16 }}>
            <Tile label="Scheduled" value={String(data.bookings.scheduled)} />
            <Tile label="Completed" value={String(data.bookings.completed)} tone="#10B981" />
            <Tile label="Cancelled" value={String(data.bookings.cancelled)} tone="#F59E0B" />
            <Tile label="No-shows" value={String(data.bookings.no_show)} tone="#EF4444" />
          </div>

          <h3 className="c-set-sub" style={{ margin: '4px 0 8px' }}>Rates</h3>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 16 }}>
            <Tile label="Completion" value={`${data.rates.completion}%`} tone="#10B981" />
            <Tile label="Cancellation" value={`${data.rates.cancellation}%`} tone="#F59E0B" />
            <Tile label="No-show" value={`${data.rates.no_show}%`} tone="#EF4444" />
          </div>

          <h3 className="c-set-sub" style={{ margin: '4px 0 8px' }}>Customers</h3>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 16 }}>
            <Tile label="Total" value={String(data.customers.total)} />
            <Tile label="Returning" value={String(data.customers.returning)} />
            <Tile label="New" value={String(data.customers.new)} />
            <Tile label="Repeat rate" value={`${data.customers.repeat_rate}%`} />
          </div>

          <h3 className="c-set-sub" style={{ margin: '4px 0 8px' }}>Reviews</h3>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <Tile label="Avg rating" value={data.reviews.average != null ? `${data.reviews.average.toFixed(1)} ★` : '—'} tone="#f5b301" />
            <Tile label="Count" value={String(data.reviews.count)} />
          </div>
        </>
      ) : null}
    </div></div>
  );
}
