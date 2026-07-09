import { useEffect, useMemo, useState, useCallback } from 'react';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getAiInsights, type AiInsights } from '@/lib/aiInsights';
import '@/styles/insights.css';

/* ---------- date helpers ---------------------------------------------------- */
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };

/* ---------- AI summary card ------------------------------------------------- */
function AiInsightsCard({ data, loading, refreshing, onRefresh }: {
  data: AiInsights | null; loading: boolean; refreshing: boolean; onRefresh: () => void;
}) {
  return (
    <div className="ins-card span2 ins-ai">
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icons.Sparkle size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">AI summary</span>
          <span className="ins-card-sub">Plain-language read on your last 30 days</span>
        </span>
        <button className="ins-ai-refresh" onClick={onRefresh} disabled={loading || refreshing}
          aria-label="Refresh AI summary">
          {refreshing ? 'Refreshing…' : 'Refresh'}
        </button>
      </div>

      {loading ? (
        <div className="ins-ai-body">
          <div className="ins-skel" style={{ height: 16, marginBottom: 8 }} />
          <div className="ins-skel" style={{ height: 16, width: '80%', marginBottom: 16 }} />
          <div className="ins-skel" style={{ height: 48 }} />
        </div>
      ) : !data || data.state === 'error' ? (
        <div className="ins-ai-body">
          <p className="ins-ai-msg">{data?.message || 'Could not generate the AI summary right now.'}</p>
          <button className="ins-ai-retry" onClick={onRefresh}>Try again</button>
        </div>
      ) : data.state === 'low_data' ? (
        <div className="ins-ai-body"><p className="ins-ai-msg">{data.message}</p></div>
      ) : (
        <div className={`ins-ai-body${refreshing ? ' is-refreshing' : ''}`}>
          <p className="ins-ai-summary">{data.summary}</p>
          {data.patterns.length > 0 && (
            <div className="ins-ai-block">
              <span className="ins-ai-label">Patterns</span>
              <ul className="ins-ai-list">{data.patterns.map((p, i) => <li key={i}>{p}</li>)}</ul>
            </div>
          )}
          {data.recommendations.length > 0 && (
            <div className="ins-ai-block">
              <span className="ins-ai-label">Recommendations</span>
              <ul className="ins-ai-list">{data.recommendations.map((r, i) => <li key={i}>{r}</li>)}</ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/* ---------- page ----------------------------------------------------------- */
export default function AiSummary() {
  const { shop } = useShop();

  // Fixed, glanceable window — the last 30 days. No date picker needed.
  const { from, to } = useMemo(() => {
    const today = new Date();
    return { from: iso(addDays(today, -29)), to: iso(today) };
  }, []);

  const [data, setData] = useState<AiInsights | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchAi = useCallback(async (refresh = false) => {
    if (!shop?.id) return;
    refresh ? setRefreshing(true) : setLoading(true);
    try {
      const res = await getAiInsights(shop.id, from, to, refresh);
      setData(res);
    } catch {
      setData({ state: 'error', summary: '', patterns: [], recommendations: [],
        message: 'Could not generate the AI summary right now.', generated_at: '', cached: false });
    } finally {
      setLoading(false); setRefreshing(false);
    }
  }, [shop?.id, from, to]);

  useEffect(() => { void fetchAi(false); }, [fetchAi]);

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">AI summary</h1>
        <p className="c-page-sub">A plain-language read on your last 30 days, written by AI.</p>
      </div>

      <div className="ins-wrap">
        <AiInsightsCard data={data} loading={loading} refreshing={refreshing} onRefresh={() => fetchAi(true)} />
      </div>
    </div></div>
  );
}
