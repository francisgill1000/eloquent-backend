import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams, Navigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import {
  getConversation, listConversations, renameConversation, deleteConversation,
  postText, postVoice, type Conversation,
} from '@/lib/assistant';
import { getSimulation, speak, type SimScript } from '@/lib/simulation';
import { useRecorder } from '@/hooks/useRecorder';

type Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null; autoPlay?: boolean };

// Past this many messages a thread is "long" — we nudge the owner to start a
// fresh chat so conversations stay focused. (The model only ever sees the last
// ~20 messages regardless — see ConversationStore::contextFor — so this is a
// UX prompt, not a hard limit.)
const LONG_CHAT = 40;

// Rotating status words shown while the assistant is working, so the wait
// feels alive instead of a dead row of dots. Business-flavoured on purpose.
const THINKING_WORDS = [
  'Thinking',
  'Crunching the numbers',
  'Checking your books',
  'Looking into it',
  'Consulting your data',
  'Working it out',
  'Almost there',
];

/** The "assistant is thinking" bubble: a phrase that rotates every ~1.5s plus
 *  a trio of bouncing dots. Replaces the old static ellipsis. */
function ThinkingBubble() {
  const [i, setI] = useState(0);
  useEffect(() => {
    const id = setInterval(() => setI((n) => (n + 1) % THINKING_WORDS.length), 1500);
    return () => clearInterval(id);
  }, []);
  return (
    <div className="va-bubble va-ai va-thinking">
      {/* key re-mounts the span each change so the fade-in animation replays */}
      <span key={i} className="va-thinking-word">{THINKING_WORDS[i]}</span>
      <span className="va-dots" aria-hidden="true"><i /><i /><i /></span>
    </div>
  );
}

function fmtTime(s: number): string {
  if (!isFinite(s) || s < 0) return '0:00';
  const m = Math.floor(s / 60);
  const sec = Math.floor(s % 60);
  return `${m}:${sec.toString().padStart(2, '0')}`;
}

/**
 * A WhatsApp-style voice-note player for one message: play/pause, a progress
 * track, and elapsed time. Auto-plays once on mount when autoPlay is set; the
 * button replays it any number of times afterwards.
 */
function AudioBubble({ src, autoPlay = false, onEnded }: { src: string; autoPlay?: boolean; onEnded?: () => void }) {
  const ref = useRef<HTMLAudioElement>(null);
  const [playing, setPlaying] = useState(false);
  const [progress, setProgress] = useState(0);
  const [elapsed, setElapsed] = useState(0);
  const [duration, setDuration] = useState(0);

  useEffect(() => {
    if (autoPlay) ref.current?.play().catch(() => undefined);
  }, [autoPlay]);

  const toggle = () => {
    const a = ref.current;
    if (!a) return;
    if (a.paused) a.play().catch(() => undefined);
    else a.pause();
  };

  return (
    <div className="va-audio">
      <button className="va-audio-btn" onClick={toggle} aria-label={playing ? 'Pause' : 'Play'}>
        {playing ? (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1" /><rect x="14" y="5" width="4" height="14" rx="1" /></svg>
        ) : (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
        )}
      </button>
      <div className="va-audio-track"><div className="va-audio-fill" style={{ width: `${progress * 100}%` }} /></div>
      <span className="va-audio-time">{fmtTime(elapsed || duration)}</span>
      <audio
        ref={ref}
        src={src}
        preload="metadata"
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onEnded={() => { setPlaying(false); setProgress(0); setElapsed(0); onEnded?.(); }}
        onLoadedMetadata={(e) => setDuration(e.currentTarget.duration)}
        onTimeUpdate={(e) => {
          const a = e.currentTarget;
          setElapsed(a.currentTime);
          setProgress(a.duration && isFinite(a.duration) ? a.currentTime / a.duration : 0);
        }}
      />
    </div>
  );
}

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
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [threads, setThreads] = useState<Conversation[]>([]);
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
        audioDone.current = resolve;
        setMessages((m) => [...m, { role: turn.who === 'assistant' ? 'assistant' : 'user', content: turn.text, audioUrl: url || null, autoPlay: !!url }]);
        if (!url) resolve(); // nothing to play — advance after a beat
      });
      await wait(400); // brief gap between voice notes
    }
    navigate('/booking/preview', { state: { booking: simScript.booking } });
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

  async function openDrawer() {
    setDrawerOpen(true);
    try { setThreads((await listConversations()).conversations); } catch { setError('Could not load your chats.'); }
  }

  async function removeThread(id: number) {
    if (!window.confirm('Delete this chat?')) return;
    try { await deleteConversation(id); } catch { setError('Could not delete the chat.'); return; }
    setThreads((t) => t.filter((c) => c.id !== id));
    if (id === conversationId) navigate('/ask'); // deleting the open thread → new chat
  }

  async function renameThread(id: number, current: string) {
    const next = window.prompt('Rename chat', current);
    if (next == null || !next.trim()) return;
    try { await renameConversation(id, next.trim()); } catch { setError('Could not rename the chat.'); return; }
    setThreads((t) => t.map((c) => (c.id === id ? { ...c, title: next.trim() } : c)));
  }

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
        <button className="c-icon-btn" aria-label="History" onClick={() => void openDrawer()}><Icons.Clock size={18} /></button>
      </div>

      {simMode && !simStarted && (
        <div className="va-drawer-backdrop" style={{ zIndex: 20 }}>
          <button className="c-btn" style={{ padding: '14px 28px', fontSize: 16 }} disabled={!simScript} onClick={() => void runSimulation()}>
            ▶ Start simulation
          </button>
        </div>
      )}

      <div className="va-thread" ref={threadRef}>
        {loadingHistory && <div className="va-bubble va-ai va-typing">…</div>}
        {!loadingHistory && messages.length === 0 && !busy && (
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
            {m.content && <div className="va-text">{m.content}</div>}
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

      {drawerOpen && (
        <div className="va-drawer-backdrop" onClick={() => setDrawerOpen(false)}>
          <div className="va-drawer" onClick={(e) => e.stopPropagation()}>
            <div className="va-drawer-head">
              <span className="va-drawer-title">Your chats</span>
              <button className="c-icon-btn" aria-label="Close" onClick={() => setDrawerOpen(false)}><Icons.ChevronLeft size={18} /></button>
            </div>
            <button className="va-drawer-new" onClick={() => { setDrawerOpen(false); navigate('/ask'); }}>
              <Icons.Plus size={16} /> New chat
            </button>
            <div className="va-drawer-list">
              {threads.length === 0 && <p className="va-drawer-empty">No past chats yet.</p>}
              {threads.map((c) => (
                <div key={c.id} className={`va-drawer-row ${c.id === conversationId ? 'active' : ''}`}>
                  <button className="va-drawer-open" onClick={() => { setDrawerOpen(false); navigate(`/ask/${c.id}`); }}>
                    <span className="va-drawer-row-title">{c.title}</span>
                    <span className="va-drawer-row-time">{new Date(c.updated_at).toLocaleDateString()}</span>
                  </button>
                  <button className="c-icon-btn" aria-label="Rename thread" onClick={() => void renameThread(c.id, c.title)}><Icons.Send size={14} /></button>
                  <button className="c-icon-btn" aria-label="Delete thread" onClick={() => void removeThread(c.id)}><Icons.Trash size={14} /></button>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
