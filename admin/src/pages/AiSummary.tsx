import { useEffect, useRef, useState, useCallback, type ReactNode } from 'react';
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
/** Split text into word/space tokens, tagging each word with a global index so a
 *  running counter can brighten words in the order they're spoken. */
function revealTokens(text: string, from: number, revealed: number) {
  let idx = from;
  const nodes = text.split(/(\s+)/).map((tok, i) => {
    if (tok === '' || /^\s+$/.test(tok)) return tok;
    const on = idx < revealed;
    idx++;
    return <span key={i} className={`ais-w${on ? ' on' : ''}`}>{tok}</span>;
  });
  return { nodes, next: idx };
}

const wordCount = (s: string) => (s.trim() === '' ? 0 : s.trim().split(/\s+/).length);

function AiInsightsCard({ data, loading, refreshing, subtitle, hint, controls, reveal, onRefresh }: {
  data: AiInsights | null; loading: boolean; refreshing: boolean; subtitle: string;
  hint?: string; controls?: ReactNode; reveal: number | null; onRefresh: () => void;
}) {
  return (
    <div className="ins-card span2 ins-ai">
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icons.Sparkle size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">AI summary</span>
          <span className="ins-card-sub">{subtitle}</span>
        </span>
      </div>

      {controls && <div className="ins-card-controls">{controls}</div>}

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
      ) : (() => {
        // Word-level reveal synced to playback: words brighten in spoken order.
        // reveal=null → everything shown (normal). reveal∈[0,1] → up to that share.
        const total = wordCount(data.summary)
          + data.patterns.reduce((n, p) => n + wordCount(p), 0)
          + data.recommendations.reduce((n, r) => n + wordCount(r), 0);
        const revealed = reveal === null ? Number.POSITIVE_INFINITY : Math.round(reveal * total);
        let wi = 0;
        const seg = (t: string) => { const r = revealTokens(t, wi, revealed); wi = r.next; return r.nodes; };
        return (
          <div className={`ins-ai-body${refreshing ? ' is-refreshing' : ''}`}>
            <p className="ins-ai-summary">{seg(data.summary)}</p>
            {data.patterns.length > 0 && (
              <div className="ins-ai-block">
                <span className="ins-ai-label">Patterns</span>
                <ul className="ins-ai-list">{data.patterns.map((p, i) => <li key={i}>{seg(p)}</li>)}</ul>
              </div>
            )}
            {data.recommendations.length > 0 && (
              <div className="ins-ai-block">
                <span className="ins-ai-label">Recommendations</span>
                <ul className="ins-ai-list">{data.recommendations.map((r, i) => <li key={i}>{seg(r)}</li>)}</ul>
              </div>
            )}
          </div>
        );
      })()}
    </div>
  );
}

/* ---------- play (mic) card -------------------------------------------------- */
function PlayCard({ text, ready, onProgress }: {
  text: string; ready: boolean; onProgress: (p: number | null) => void;
}) {
  const [status, setStatus] = useState<'idle' | 'loading' | 'playing'>('idle');
  const audioRef = useRef<HTMLAudioElement | null>(null);
  useEffect(() => () => { audioRef.current?.pause(); }, []);

  const toggle = async () => {
    // Playing → tap stops it (and clears the reveal so the full text shows).
    if (status === 'playing') { audioRef.current?.pause(); setStatus('idle'); onProgress(null); return; }
    if (!ready || !text) return;
    audioRef.current?.pause();          // start fresh (replay from the beginning)
    try {
      setStatus('loading');
      const url = await speak(text.slice(0, 900), 'nova');
      const el = new Audio(url);
      audioRef.current = el;
      // Reveal the words in step with playback progress (0 → 1).
      el.ontimeupdate = () => { if (el.duration > 0) onProgress(el.currentTime / el.duration); };
      el.onended = () => { setStatus('idle'); onProgress(null); URL.revokeObjectURL(url); };
      el.onerror = () => { setStatus('idle'); onProgress(null); };
      await el.play();
      setStatus('playing');
      onProgress(0);
    } catch { setStatus('idle'); onProgress(null); }
  };

  return (
    <div className="ais-play-card">
      <button className={`ais-mic${status === 'playing' ? ' is-playing' : ''}`}
        onClick={toggle} disabled={!ready || status === 'loading'}
        aria-label={status === 'playing' ? 'Stop' : 'Play summary'}>
        <span className="ais-mic-rings" aria-hidden="true"><i /><i /><i /></span>
        <span className="ais-mic-core">
          {status === 'playing'
            ? <span className="ais-eq" aria-hidden="true"><i /><i /><i /><i /><i /></span>
            : <Icons.Mic size={38} />}
        </span>
      </button>
      <p className="ais-play-title">
        {status === 'loading' ? 'Preparing…' : status === 'playing' ? 'Speaking…' : ready ? 'Play summary' : 'No summary yet'}
      </p>
      <p className="ais-play-sub">
        {status === 'playing' ? 'Tap to stop.'
          : ready ? 'Tap to hear your summary read aloud — tap again to replay.'
          : 'Generate a summary to listen.'}
      </p>
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
  // The Custom tab toggles the date picker open/closed on repeat clicks.
  const [customOpen, setCustomOpen] = useState(false);
  // Word-reveal progress (0→1) driven by the play card; null = show full text.
  const [reveal, setReveal] = useState<number | null>(null);

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

  const controls = (
    <>
      <div className="ins-tabs-row">
        <div className="ins-tabs">
          {TABS.map((t) => {
            // Custom acts as a toggle: re-clicking it hides the picker; other tabs
            // are a plain single-select. The Custom tab is "active" only while open.
            const active = t.id === 'custom' ? (period === 'custom' && customOpen) : period === t.id;
            const onClick = t.id === 'custom'
              ? () => { setCustomOpen((o) => (period === 'custom' ? !o : true)); setPeriod('custom'); }
              : () => setPeriod(t.id);
            return (
              <button key={t.id} aria-pressed={active}
                className={`ins-tab${active ? ' is-active' : ''}`}
                onClick={onClick}>{t.label}</button>
            );
          })}
        </div>

        {/* Custom range — kept mounted so it can animate open/closed (0fr→1fr grid
            collapse). aria-hidden keeps the collapsed picker out of the a11y tree. */}
        <div className={`ins-custom-wrap${period === 'custom' && customOpen ? ' is-open' : ''}`}
          aria-hidden={!(period === 'custom' && customOpen)}>
          <div className="ins-custom">
            <DateRangePicker from={customFrom} to={customTo}
              onChange={(f, t) => { setCustomFrom(f); setCustomTo(t); }}
              footer={<button className="drp-go" disabled={!customFrom || !customTo}
                onClick={() => { runCustom(); setCustomOpen(false); }}>Submit</button>} />
          </div>
        </div>
      </div>

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
    </>
  );

  const spokenText = data && data.state === 'ok'
    ? [data.summary, ...data.patterns, ...data.recommendations].filter(Boolean).join('. ')
    : '';

  return (
    <div className="m-screen"><div className="m-scroll c-aisummary">
      <div className="ais-layout">
        <div className="ais-left">
          <PlayCard text={spokenText} ready={!!spokenText} onProgress={setReveal} />
        </div>
        <div className="ais-right">
          <AiInsightsCard data={data} loading={loading} refreshing={refreshing}
            subtitle={win.label} controls={controls} reveal={reveal}
            hint={period === 'custom' ? 'Pick a date range, then tap Submit to see a summary.' : undefined}
            onRefresh={() => fetchAi(win.from, win.to, true)} />
        </div>
      </div>
    </div></div>
  );
}
