import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getAiInsights, type AiInsights } from '@/lib/aiInsights';
import { speak } from '@/lib/simulation';
import '@/styles/insights.css';

/* ---------- date helpers ---------------------------------------------------- */
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };

/* ---------- AI summary card ------------------------------------------------- */
function AiInsightsCard({ data, loading, refreshing, onRefresh }: {
  data: AiInsights | null; loading: boolean; refreshing: boolean; onRefresh: () => void;
}) {
  const [audio, setAudio] = useState<'idle' | 'loading' | 'playing'>('idle');
  const audioRef = useRef<HTMLAudioElement | null>(null);

  // Stop any playback when the card unmounts.
  useEffect(() => () => { audioRef.current?.pause(); }, []);

  const canListen = !!data && data.state === 'ok';

  const onListen = async () => {
    if (audio === 'playing') { audioRef.current?.pause(); setAudio('idle'); return; }
    if (!data || data.state !== 'ok') return;
    // Read the whole summary aloud (server caps length); nova is the default voice.
    const text = [data.summary, ...data.patterns, ...data.recommendations]
      .filter(Boolean).join('. ').slice(0, 780);
    try {
      setAudio('loading');
      const url = await speak(text, 'nova');
      const el = new Audio(url);
      audioRef.current = el;
      el.onended = () => { setAudio('idle'); URL.revokeObjectURL(url); };
      el.onerror = () => setAudio('idle');
      await el.play();
      setAudio('playing');
    } catch {
      setAudio('idle');
    }
  };

  return (
    <div className="ins-card span2 ins-ai">
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icons.Sparkle size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">AI summary</span>
          <span className="ins-card-sub">Plain-language read on your last 30 days</span>
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

  // Fixed, glanceable window — the 30 complete days ending yesterday (today is
  // still in progress). Matches the overnight job so the morning load is served
  // from the pre-generated summary, not a fresh Claude call. No date picker.
  const { from, to } = useMemo(() => {
    const yesterday = addDays(new Date(), -1);
    return { from: iso(addDays(yesterday, -29)), to: iso(yesterday) };
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
