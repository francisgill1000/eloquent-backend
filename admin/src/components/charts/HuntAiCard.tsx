import { useCallback, useEffect, useRef, useState } from 'react';
import { Icons } from '@/components/Icons';
import { getAiInsights, type AiInsights } from '@/lib/aiInsights';
import { speak } from '@/lib/simulation';

/** localStorage epoch (ms) until which the card stays hidden. */
const HIDE_KEY = 'hunt_ai_hidden_until';

/**
 * The AI narrative for the Hunt Overview, embedded in the dashboard flow. It has
 * no date controls of its own — it reads the page's active range (from/to) and
 * re-generates whenever that range changes, so it stays in sync with the top
 * filter. period is always 'custom' here; an uncached range triggers one live
 * generation server-side (then cached 24h), matching the standalone AI page.
 */
export function HuntAiCard({ shopId, from, to, rangeLabel }: {
  shopId: number; from: string; to: string; rangeLabel: string;
}) {
  const [data, setData] = useState<AiInsights | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Temporary dismiss: "Hide" tucks the card away for 24h, then it returns on
  // its own — a low-commitment way to try the page without it before deciding.
  const [hidden, setHidden] = useState(() => Date.now() < Number(localStorage.getItem(HIDE_KEY) || 0));
  const hide = () => { localStorage.setItem(HIDE_KEY, String(Date.now() + 864e5)); setHidden(true); };

  const fetchAi = useCallback(async (refresh: boolean) => {
    if (!shopId || !from || !to) return;
    refresh ? setRefreshing(true) : setLoading(true);
    try {
      setData(await getAiInsights(shopId, from, to, refresh, 'custom'));
    } catch {
      setData({ state: 'error', summary: '', patterns: [], recommendations: [],
        message: 'Could not generate the AI summary right now.', generated_at: '', cached: false });
    } finally { setLoading(false); setRefreshing(false); }
  }, [shopId, from, to]);

  // Auto-generate on mount and whenever the top filter range changes — but not
  // while hidden, so a dismissed card never spends a generation.
  useEffect(() => { if (!hidden) void fetchAi(false); }, [fetchAi, hidden]);

  /* ---- listen (TTS) ---- */
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const [tts, setTts] = useState<'idle' | 'loading' | 'playing'>('idle');
  useEffect(() => () => { audioRef.current?.pause(); }, []);
  // A new range invalidates the spoken text — stop any playback.
  useEffect(() => { audioRef.current?.pause(); setTts('idle'); }, [from, to]);

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

  // Tucked away for now — comes back after 24h.
  if (hidden) return null;

  return (
    <div className="ins-card span2 ins-ai">
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icons.Sparkle size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">AI summary</span>
          <span className="ins-card-sub">{rangeLabel}</span>
        </span>
        <div className="ins-ai-actions">
          {data?.state === 'ok' && (
            <>
              <button className="ins-ai-listen" onClick={toggleListen}
                disabled={tts === 'loading' || !spokenText}
                aria-label={tts === 'playing' ? 'Stop' : 'Listen'}>
                {tts === 'playing' ? <Icons.Stop size={15} /> : <Icons.Speaker size={15} />}
                {tts === 'playing' ? 'Stop' : tts === 'loading' ? 'Loading…' : 'Listen'}
              </button>
              <button className="ins-ai-refresh" onClick={() => fetchAi(true)} disabled={refreshing}>
                {refreshing ? 'Regenerating…' : 'Regenerate'}
              </button>
            </>
          )}
          <button className="ins-ai-hide" onClick={hide} title="Hide for 24 hours">Hide</button>
        </div>
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
