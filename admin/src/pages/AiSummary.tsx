import { useEffect, useRef, useState, useCallback } from 'react';
import { Icons } from '@/components/Icons';
import { DateRangePicker } from '@/components/DateRangePicker';
import { useShop } from '@/context/ShopContext';
import {
  getAiInsights, getAiSummaryHistory,
  type AiInsights, type PeriodType, type AiSummaryHistoryItem,
} from '@/lib/aiInsights';
import { speak } from '@/lib/simulation';
import '@/styles/insights.css';

/* ---------- date helpers ---------------------------------------------------- */
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const startOfWeekMon = (d: Date) => { const x = new Date(d); const dow = (x.getDay() + 6) % 7; return addDays(x, -dow); };
const startOfMonth = (d: Date) => new Date(d.getFullYear(), d.getMonth(), 1);

/** The from/to window + human label for a given period selection (current period). */
function currentWindow(period: PeriodType): { from: string; to: string; label: string } {
  const today = new Date();
  if (period === 'week') {
    return { from: iso(startOfWeekMon(today)), to: iso(today), label: 'This week so far' };
  }
  if (period === 'month') {
    return { from: iso(startOfMonth(today)), to: iso(today), label: 'This month so far' };
  }
  // rolling30 (default): the 30 complete days ending yesterday.
  const yesterday = addDays(today, -1);
  return { from: iso(addDays(yesterday, -29)), to: iso(yesterday), label: 'Last 30 days' };
}

const fmt = (s: string) => new Date(s + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const historyLabel = (it: AiSummaryHistoryItem) => `${fmt(it.period_from)} – ${fmt(it.period_to)}`;

/* ---------- AI summary card ------------------------------------------------- */
function AiInsightsCard({ data, loading, refreshing, subtitle, hint, onRefresh }: {
  data: AiInsights | null; loading: boolean; refreshing: boolean; subtitle: string; hint?: string; onRefresh: () => void;
}) {
  const [audio, setAudio] = useState<'idle' | 'loading' | 'playing'>('idle');
  const audioRef = useRef<HTMLAudioElement | null>(null);
  useEffect(() => () => { audioRef.current?.pause(); }, []);
  const canListen = !!data && data.state === 'ok';

  const onListen = async () => {
    if (audio === 'playing') { audioRef.current?.pause(); setAudio('idle'); return; }
    if (!data || data.state !== 'ok') return;
    const text = [data.summary, ...data.patterns, ...data.recommendations].filter(Boolean).join('. ').slice(0, 780);
    try {
      setAudio('loading');
      const url = await speak(text, 'nova');
      const el = new Audio(url);
      audioRef.current = el;
      el.onended = () => { setAudio('idle'); URL.revokeObjectURL(url); };
      el.onerror = () => setAudio('idle');
      await el.play();
      setAudio('playing');
    } catch { setAudio('idle'); }
  };

  return (
    <div className="ins-card span2 ins-ai">
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icons.Sparkle size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">AI summary</span>
          <span className="ins-card-sub">{subtitle}</span>
        </span>
        {canListen && (
          <div className="ins-ai-actions">
            <button className="ins-ai-listen" onClick={onListen} disabled={audio === 'loading'}
              aria-label={audio === 'playing' ? 'Stop' : 'Listen'}>
              {audio === 'playing' ? <Icons.Stop size={14} /> : <Icons.Speaker size={14} />}
              {audio === 'loading' ? 'Loading…' : audio === 'playing' ? 'Stop' : 'Listen'}
            </button>
          </div>
        )}
      </div>

      {loading ? (
        <div className="ins-ai-body">
          <div className="ins-skel" style={{ height: 16, marginBottom: 8 }} />
          <div className="ins-skel" style={{ height: 16, width: '80%', marginBottom: 16 }} />
          <div className="ins-skel" style={{ height: 48 }} />
        </div>
      ) : (!data && hint) ? (
        <div className="ins-ai-body"><p className="ins-ai-msg">{hint}</p></div>
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
const TABS: { id: PeriodType; label: string }[] = [
  { id: 'rolling30', label: '30-day' },
  { id: 'week', label: 'Weekly' },
  { id: 'month', label: 'Monthly' },
  { id: 'custom', label: 'Custom' },
];

export default function AiSummary() {
  const { shop } = useShop();
  const [period, setPeriod] = useState<PeriodType>('rolling30');

  // The active window: the current period, a picked history row, or a custom range.
  const [win, setWin] = useState(() => currentWindow('rolling30'));
  const [history, setHistory] = useState<AiSummaryHistoryItem[]>([]);
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');

  const [data, setData] = useState<AiInsights | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchAi = useCallback(async (from: string, to: string, refresh = false) => {
    if (!shop?.id || !from || !to) return;
    refresh ? setRefreshing(true) : setLoading(true);
    try {
      setData(await getAiInsights(shop.id, from, to, refresh, period));
    } catch {
      setData({ state: 'error', summary: '', patterns: [], recommendations: [],
        message: 'Could not generate the AI summary right now.', generated_at: '', cached: false });
    } finally { setLoading(false); setRefreshing(false); }
  }, [shop?.id, period]);

  // On period change: reset to that period's current window + load its history.
  useEffect(() => {
    if (period === 'custom') { setData(null); setLoading(false); return; }
    const w = currentWindow(period);
    setWin(w);
    void fetchAi(w.from, w.to, false);
    if (period === 'week' || period === 'month') {
      getAiSummaryHistory(shop!.id, period, 1).then((r) => setHistory(r.data)).catch(() => setHistory([]));
    } else {
      setHistory([]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [period, shop?.id]);

  const pickHistory = (it: AiSummaryHistoryItem) => {
    setWin({ from: it.period_from, to: it.period_to, label: historyLabel(it) });
    // History rows are already stored — a normal (non-refresh) fetch serves them instantly.
    void fetchAi(it.period_from, it.period_to, false);
  };

  const runCustom = () => {
    if (!customFrom || !customTo) return;
    setWin({ from: customFrom, to: customTo, label: `${fmt(customFrom)} – ${fmt(customTo)}` });
    void fetchAi(customFrom, customTo, false);
  };

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">AI summary</h1>
        <p className="c-page-sub">A plain-language read on your business, written by AI.</p>
      </div>

      <div className="ins-tabs">
        {TABS.map((t) => (
          <button key={t.id} aria-pressed={period === t.id}
            className={`ins-tab${period === t.id ? ' is-active' : ''}`}
            onClick={() => setPeriod(t.id)}>{t.label}</button>
        ))}
      </div>

      {period === 'custom' && (
        <div className="ins-custom">
          <DateRangePicker from={customFrom} to={customTo}
            onChange={(f, t) => { setCustomFrom(f); setCustomTo(t); }} />
          <div className="ins-custom-foot">
            <span className="ins-custom-range">
              {customFrom && customTo ? `${fmt(customFrom)} – ${fmt(customTo)}` : 'Select a start and end date'}
            </span>
            <button className="ins-custom-go" onClick={runCustom} disabled={!customFrom || !customTo}>Generate</button>
          </div>
        </div>
      )}

      {(period === 'week' || period === 'month') && history.length > 0 && (
        <div className="ins-history">
          <button className={`ins-hist-item${win.label.includes('so far') ? ' is-active' : ''}`}
            onClick={() => { const w = currentWindow(period); setWin(w); void fetchAi(w.from, w.to, false); }}>
            {period === 'week' ? 'This week' : 'This month'}
          </button>
          {history.map((it) => (
            <button key={`${it.period_from}_${it.period_to}`}
              className={`ins-hist-item${win.from === it.period_from && win.to === it.period_to ? ' is-active' : ''}`}
              onClick={() => pickHistory(it)}>{historyLabel(it)}</button>
          ))}
        </div>
      )}

      <div className="ins-wrap">
        <AiInsightsCard data={data} loading={loading} refreshing={refreshing}
          subtitle={win.label}
          hint={period === 'custom' ? 'Pick a date range above, then tap Generate to see a summary.' : undefined}
          onRefresh={() => fetchAi(win.from, win.to, true)} />
      </div>
    </div></div>
  );
}
