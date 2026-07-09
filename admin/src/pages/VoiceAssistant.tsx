import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams, Navigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getConversation, postText, postVoice } from '@/lib/assistant';
import { getSimulation, speak, type SimScript } from '@/lib/simulation';
import { createBooking } from '@/lib/bookings';
import { useRecorder } from '@/hooks/useRecorder';
import { AudioBubble, ThinkingBubble, renderContent } from '@/components/chat';

type Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null; autoPlay?: boolean };

// Past this many messages a thread is "long" — we nudge the owner to start a
// fresh chat so conversations stay focused. (The model only ever sees the last
// ~20 messages regardless — see ConversationStore::contextFor — so this is a
// UX prompt, not a hard limit.)
const LONG_CHAT = 40;

export default function VoiceAssistant() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const { conversationId: routeId } = useParams<{ conversationId?: string }>();
  const cid = routeId ? Number(routeId) : null;

  const [conversationId, setConversationId] = useState<number | null>(cid);
  const [messages, setMessages] = useState<Msg[]>([]);
  const [loadingHistory, setLoadingHistory] = useState(cid != null);
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState('');
  const [error, setError] = useState('');
  const { recording, start, stop, supported } = useRecorder();
  const threadRef = useRef<HTMLDivElement>(null);
  // Messages loaded from the server should not auto-play their audio; only
  // notes added during this session do. Reset whenever we (re)load a thread.
  const restoredCount = useRef(0);

  const [params] = useSearchParams();
  const simMode = params.get('sim') === '1';
  const [simScript, setSimScript] = useState<SimScript | null>(null);
  const [simStarted, setSimStarted] = useState(false);
  const [simThinking, setSimThinking] = useState(false);
  // Resolves when the currently-playing sim bubble finishes.
  const audioDone = useRef<(() => void) | null>(null);

  // Load the script when entering sim mode.
  useEffect(() => {
    if (!simMode) return;
    let alive = true;
    getSimulation().then((s) => { if (alive) setSimScript(s); }).catch(() => { if (alive) setError('Could not load the simulation.'); });
    return () => { alive = false; };
  }, [simMode]);

  async function runSimulation() {
    if (!simScript) return;
    setSimStarted(true);
    setMessages([]);
    const wait = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));
    for (const turn of simScript.turns) {
      if (turn.who === 'assistant') {
        setSimThinking(true);
        await wait(simScript.thinking_ms);
        setSimThinking(false);
      }
      let url = '';
      try { url = await speak(turn.text, simScript.voices[turn.who]); } catch { /* show text only */ }
      await new Promise<void>((resolve) => {
        let settled = false;
        const finish = () => { if (settled) return; settled = true; audioDone.current = null; resolve(); };
        audioDone.current = finish;
        setMessages((m) => [...m, { role: turn.who === 'assistant' ? 'assistant' : 'user', content: turn.text, audioUrl: url || null, autoPlay: !!url }]);
        if (!url) { finish(); return; } // nothing to play — advance after a beat
        // Failsafe: if the audio never signals 'ended' (silent playback failure),
        // advance anyway so a recording never hangs. Scaled to line length; only a
        // fallback — normal playback resolves via onEnded well before this.
        const cap = Math.max(15000, turn.text.length * 120);
        setTimeout(finish, cap);
      });
      await wait(400); // brief gap between voice notes
    }
    // Record the booking for real so the demo ends on the true detail page
    // (full status/timeline/fields), exactly like a genuine booking. Falls back
    // to the read-only preview if the create call fails, so a take never dead-ends.
    const b = simScript.booking;
    try {
      const created = await createBooking(shop!.id, {
        customer_name: b.customer_name,
        customer_whatsapp: b.customer_phone,
        date: b.date,
        start_time: b.start_time,
        services: [{ title: b.service, price: b.price }],
        charges: Number(b.price) || 0,
      });
      navigate(`/booking/${created.id}`);
    } catch {
      navigate('/booking/preview', { state: { booking: b } });
    }
  }

  // Load the thread named in the route, or start fresh when there is none.
  useEffect(() => {
    let alive = true;
    setConversationId(cid);
    if (cid == null) {
      setMessages([]);
      restoredCount.current = 0;
      setLoadingHistory(false);
      return;
    }
    setLoadingHistory(true);
    getConversation(cid)
      .then((history) => {
        if (!alive) return;
        const msgs: Msg[] = history.map((m) => ({ role: m.role, content: m.content, audioUrl: m.audio_url }));
        restoredCount.current = msgs.length;
        setMessages(msgs);
      })
      .catch(() => { if (alive) setError('Could not load this conversation.'); })
      .finally(() => { if (alive) setLoadingHistory(false); });
    return () => { alive = false; };
  }, [cid]);

  // Keep the latest message in view as the conversation grows.
  useEffect(() => {
    threadRef.current?.scrollTo?.({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, busy]);

  // After the first successful send in a new thread, adopt its id + route.
  function adopt(id?: number) {
    if (id != null && conversationId == null) {
      setConversationId(id);
      navigate(`/ask/${id}`, { replace: true });
    }
  }

  async function send(text: string) {
    if (!text.trim() || busy) return;
    setBusy(true); setError('');
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setDraft('');
    try {
      const res = await postText(text, conversationId ?? undefined);
      setMessages((m) => [...m, { role: 'assistant', content: res.reply_text, audioUrl: res.reply_audio_url }]);
      adopt(res.conversation_id);
      if (res.action?.type === 'navigate') navigate(res.action.route);
    } catch { setError('Could not reach the assistant.'); }
    finally { setBusy(false); }
  }

  async function toggleMic() {
    if (recording) {
      setBusy(true);
      const blob = await stop();
      if (!blob) { setBusy(false); return; }
      const voiceUrl = URL.createObjectURL(blob); // play back the owner's own note
      try {
        const res = await postVoice(blob, conversationId ?? undefined);
        setMessages((m) => [
          ...m,
          { role: 'user', content: res.transcript ?? '', audioUrl: voiceUrl },
          { role: 'assistant', content: res.reply_text, audioUrl: res.reply_audio_url },
        ]);
        adopt(res.conversation_id);
        if (res.action?.type === 'navigate') navigate(res.action.route);
      } catch {
        setMessages((m) => [...m, { role: 'user', content: '', audioUrl: voiceUrl }]);
        setError('Could not reach the assistant.');
      }
      finally { setBusy(false); }
    } else {
      setError('');
      try { await start(); } catch { setError('Microphone permission needed.'); }
    }
  }

  // A master account operates the "All Businesses" view, not a single shop's
  // assistant — send it there instead of the (now home) Ask screen.
  if (shop?.is_master) return <Navigate to="/master" replace />;

  return (
    <div className="m-screen va-screen">
      <div className="va-head">
        <button className="c-icon-btn" aria-label="Back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={18} /></button>
        <div className="va-head-text">
          <span className="va-title">Ask me anything</span>
          <span className="va-sub">Ask anything — or tell me to do something</span>
        </div>
        <button className="c-icon-btn" aria-label="New chat" onClick={() => navigate('/ask')}><Icons.Plus size={18} /></button>
      </div>

      {simMode && !simStarted && (
        <div className="va-sim-overlay">
          <button className="va-sim-start" aria-label="Start simulation" disabled={!simScript} onClick={() => void runSimulation()}>
            <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
          </button>
          <span className="va-sim-cap">{simScript ? 'Tap to start' : 'Loading…'}</span>
        </div>
      )}

      <div className="va-thread" ref={threadRef}>
        {loadingHistory && <div className="va-bubble va-ai va-typing">…</div>}
        {/* The "new chat" prompt only belongs on a fresh chat — not when opening
            an existing conversation (which may briefly, or genuinely, have no
            messages loaded). */}
        {!loadingHistory && messages.length === 0 && !busy && conversationId == null && (
          <div className="va-empty">
            <div className="va-empty-mic"><Icons.Mic size={26} /></div>
            <p className="va-hint">Tap the mic and ask or tell me, e.g.<br />"How much did I make this month?" or "Cancel Sarah's 3 o'clock"</p>
          </div>
        )}
        {messages.map((m, i) => (
          <div key={i} className={`va-bubble ${m.role === 'user' ? 'va-user' : 'va-ai'}`}>
            {m.audioUrl && (
              <AudioBubble
                src={m.audioUrl}
                autoPlay={m.autoPlay ?? (m.role === 'assistant' && i >= restoredCount.current)}
                onEnded={() => { if (audioDone.current) { const done = audioDone.current; audioDone.current = null; done(); } }}
              />
            )}
            {m.content && <div className="va-text">{renderContent(m.content)}</div>}
          </div>
        ))}
        {(busy || simThinking) && <ThinkingBubble />}
        {error && <div className="c-error-box">{error}</div>}
      </div>

      {messages.length >= LONG_CHAT && (
        <div className="va-nudge">
          <span>This chat is getting long — start a fresh one for the best answers.</span>
          <button className="va-nudge-btn" onClick={() => navigate('/ask')}>Start new chat</button>
        </div>
      )}

      {!simMode && (
        <div className="va-controls">
          <input className="va-input" placeholder="Type a question…" value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') void send(draft); }} disabled={busy} />
          <button className="c-btn" aria-label="Send" disabled={busy || !draft.trim()} onClick={() => void send(draft)}>
            <Icons.Send size={16} />
          </button>
          {supported && (
            <button className={`va-mic ${recording ? 'recording' : ''}`} aria-label="Microphone" disabled={busy && !recording} onClick={() => void toggleMic()}>
              <Icons.Mic size={20} />
            </button>
          )}
        </div>
      )}

    </div>
  );
}
