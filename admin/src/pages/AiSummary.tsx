import { useEffect, useMemo, useState, useCallback } from 'react';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getAiInsights, type AiInsights } from '@/lib/aiInsights';
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
    case 'mtd': return { from: iso(new Date(y, m, 1)), to: iso(new Date(y, m + 1, 0)) };
    case 'lastmonth': return { from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) };
    case 'ytd': return { from: iso(new Date(y, 0, 1)), to: iso(today) };
  }
}

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
          <span className="ins-card-sub">Plain-language read on this period</span>
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
  const today = useMemo(() => new Date(), []);

  const [preset, setPreset] = useState<PresetKey>('30d');
  const initial = useMemo(() => presetRange('30d', today), [today]);
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);

  // Normalised, so an inverted custom range still behaves.
  const nf = from <= to ? from : to;
  const nt = from <= to ? to : from;

  const choosePreset = (key: Exclude<PresetKey, 'custom'>) => {
    const r = presetRange(key, today);
    setPreset(key); setFrom(r.from); setTo(r.to);
  };

  const [data, setData] = useState<AiInsights | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchAi = useCallback(async (refresh = false) => {
    if (!shop?.id) return;
    refresh ? setRefreshing(true) : setLoading(true);
    try {
      const res = await getAiInsights(shop.id, nf, nt, refresh);
      setData(res);
    } catch {
      setData({ state: 'error', summary: '', patterns: [], recommendations: [],
        message: 'Could not generate the AI summary right now.', generated_at: '', cached: false });
    } finally {
      setLoading(false); setRefreshing(false);
    }
  }, [shop?.id, nf, nt]);

  useEffect(() => { void fetchAi(false); }, [fetchAi]);

  const rangeLen = daysBetween(nf, nt);

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">AI summary</h1>
        <p className="c-page-sub">A plain-language read on your performance, written by AI.</p>
      </div>

      <div className="ins-wrap">
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
            <input className="ins-date-input" type="date" value={to} min={from}
              onChange={(e) => { setTo(e.target.value); setPreset('custom'); }} aria-label="To date" />
            <span className="ins-active"><b>{fmtLong(nf)}</b> – <b>{fmtLong(nt)}</b> · {rangeLen} day{rangeLen === 1 ? '' : 's'}</span>
          </div>
        </div>

        <AiInsightsCard data={data} loading={loading} refreshing={refreshing} onRefresh={() => fetchAi(true)} />
      </div>
    </div></div>
  );
}
