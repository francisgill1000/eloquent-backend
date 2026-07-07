import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getReviews, type Review, type ReviewSummary } from '@/lib/reviews';

function Stars({ n }: { n: number }) {
  return <span aria-label={`${n} stars`}>{'★'.repeat(n)}{'☆'.repeat(5 - n)}</span>;
}

export default function Reviews() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const [rows, setRows] = useState<Review[]>([]);
  const [summary, setSummary] = useState<ReviewSummary>({ count: 0, average: null, distribution: {} });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const fetch = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true);
    try {
      const res = await getReviews(shop.id);
      setRows(res.data);
      setSummary(res.summary);
    } catch {
      setError('Could not load reviews.');
    } finally {
      setLoading(false);
    }
  }, [shop?.id]);

  useEffect(() => { void fetch(); }, [fetch]);

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate('/settings')}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Reviews</h1>
        <p className="c-page-sub">Ratings from customers after their visit. Low ratings are private to you.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {loading ? (
        <Spinner label="Loading reviews…" />
      ) : (
        <>
          <div className="c-stat-row" style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
            <div className="c-set-link" style={{ flex: '1 1 140px', flexDirection: 'column', alignItems: 'flex-start' }}>
              <span className="c-set-sub">Average rating</span>
              <span className="c-page-title" style={{ fontSize: 28 }}>
                {summary.average != null ? summary.average.toFixed(1) : '—'} <span style={{ color: '#f5b301' }}>★</span>
              </span>
            </div>
            <div className="c-set-link" style={{ flex: '1 1 140px', flexDirection: 'column', alignItems: 'flex-start' }}>
              <span className="c-set-sub">Total reviews</span>
              <span className="c-page-title" style={{ fontSize: 28 }}>{summary.count}</span>
            </div>
          </div>

          {rows.length === 0 ? (
            <EmptyState title="No reviews yet" subtitle="After a completed booking, customers are asked to rate their visit." />
          ) : (
            <div className="c-set-grid" style={{ gap: 10 }}>
              {rows.map((r) => (
                <div key={r.id} className="c-set-link" style={{ flexDirection: 'column', alignItems: 'flex-start', gap: 4 }}>
                  <span style={{ color: '#f5b301', fontSize: 16 }}><Stars n={r.rating} /></span>
                  <span className="c-set-label">{r.customer_name || 'Customer'}</span>
                  {r.comment && <span className="c-set-sub">“{r.comment}”</span>}
                  <span className="c-set-sub" style={{ opacity: 0.7 }}>{r.date ?? ''}</span>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div></div>
  );
}
