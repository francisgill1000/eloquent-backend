import { useCallback, useEffect, useRef, useState } from 'react';
import { Icons } from '@/components/Icons';
import { getAiInsights, type AiInsights } from '@/lib/aiInsights';
import { speak } from '@/lib/simulation';

/* The rolling 30-day window ending yesterday — matches how the nightly job
   stores its summary, so the server serves the precomputed row for free. */
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
function rolling30Window() {
  const yesterday = addDays(new Date(), -1);
  return { from: iso(addDays(yesterday, -29)), to: iso(yesterday) };
}

/**
 * The AI narrative for the Hunt Overview. It deliberately does NOT follow the
 * page's date filter: it shows the shop's nightly-generated `rolling30` summary,
 * which is precomputed and served free/instant. Tying it to arbitrary filter
 * ranges would force a live (billable) generation on every range change, which
 * we don't want here — the standalone AI page is where you explore other ranges.
 */
export function HuntAiCard({ shopId }: { shopId: number }) {
  const [data, setData] = useState<AiInsights | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchAi = useCallback(async (refresh: boolean) => {
    if (!shopId) return;
    refresh ? setRefreshing(true) : setLoading(true);
    const { from, to } = rolling30Window();
    try {
      setData(await getAiInsights(shopId, from, to, refresh, 'rolling30'));
    } catch {
      setData({ state: 'error', summary: '', patterns: [], recommendations: [],
        message: 'Could not load the AI summary right now.', generated_at: '', cached: false });
    } finally { setLoading(false); setRefreshing(false); }
  }, [shopId]);

  // Load the nightly summary once. No refetch on filter change (see note above).
  useEffect(() => { void fetchAi(false); }, [fetchAi]);

  /* ---- listen (TTS) ---- */
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const [tts, setTts] = useState<'idle' | 'loading' | 'playing'>('idle');
  useEffect(() => () => { audioRef.current?.pause(); }, []);

  const spokenText = data && data.state === 'ok'
    ? [data.summary, ...data.patterns, ...data.recommendations].filter(Boolean).join('. ') : '';

  const toggleListen = async () => {
    if (tts === 'playing') { audioRef.current?.pause(); setTts('idle'); return; }
    if (!spokenText) return;
    audioRef.current?.pause();
    try {
      setTts('loading');
      const url = await speak(spokenText.slice(0, 900), 'nova');
      const el = new Audio(url);
      audioRef.current = el;
      el.onended = () => { setTts('idle'); URL.revokeObjectURL(url); };
      el.onerror = () => setTts('idle');
      await el.play();
      setTts('playing');
    } catch { setTts('idle'); }
  };

  return (
    <div className="ins-card span2 ins-ai">
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icons.Sparkle size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">AI summary</span>
          <span className="ins-card-sub">Last 30 days · updated nightly</span>
        </span>
        {data?.state === 'ok' && (
          <div className="ins-ai-actions">
            <button className="ins-ai-listen" onClick={toggleListen}
              disabled={tts === 'loading' || !spokenText}
              aria-label={tts === 'playing' ? 'Stop' : 'Listen'}>
              {tts === 'playing' ? <Icons.Stop size={15} /> : <Icons.Speaker size={15} />}
              {tts === 'playing' ? 'Stop' : tts === 'loading' ? 'Loading…' : 'Listen'}
            </button>
            <button className="ins-ai-refresh" onClick={() => fetchAi(true)} disabled={refreshing}>
              {refreshing ? 'Refreshing…' : 'Refresh'}
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
          <p className="ins-ai-msg">{data?.message || 'Could not load the AI summary right now.'}</p>
          <button className="ins-ai-retry" onClick={() => fetchAi(true)}>Try again</button>
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
