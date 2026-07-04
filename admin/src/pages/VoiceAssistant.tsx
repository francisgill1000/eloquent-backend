import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getHistory, clearHistory, postText, postVoice } from '@/lib/assistant';
import { useRecorder } from '@/hooks/useRecorder';

type Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null };

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
function AudioBubble({ src, autoPlay = false }: { src: string; autoPlay?: boolean }) {
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
        onEnded={() => { setPlaying(false); setProgress(0); setElapsed(0); }}
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
  const [messages, setMessages] = useState<Msg[]>([]);
  const [loadingHistory, setLoadingHistory] = useState(true);
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState('');
  const [error, setError] = useState('');
  const { recording, start, stop, supported } = useRecorder();
  const threadRef = useRef<HTMLDivElement>(null);
  // Messages loaded from the server on open should not auto-play their audio;
  // only notes added during this session do. Updated once history loads.
  const restoredCount = useRef(0);

  // Load this shop's conversation from the server on open. The server scopes by
  // auth token, so there is no cross-shop leak and no local persistence.
  useEffect(() => {
    let alive = true;
    getHistory()
      .then((history) => {
        if (!alive) return;
        const msgs: Msg[] = history.map((m) => ({ role: m.role, content: m.content, audioUrl: m.audio_url }));
        restoredCount.current = msgs.length;
        setMessages(msgs);
      })
      .catch(() => { if (alive) setError('Could not load your conversation.'); })
      .finally(() => { if (alive) setLoadingHistory(false); });
    return () => { alive = false; };
  }, []);

  // Keep the latest message in view as the conversation grows.
  useEffect(() => {
    threadRef.current?.scrollTo?.({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, busy]);

  async function clearConversation() {
    setError('');
    try { await clearHistory(); } catch { setError('Could not clear the conversation.'); return; }
    setMessages([]);
    restoredCount.current = 0;
  }

  async function send(text: string) {
    if (!text.trim() || busy) return;
    setBusy(true); setError('');
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setDraft('');
    try {
      const res = await postText(text);
      setMessages((m) => [...m, { role: 'assistant', content: res.reply_text, audioUrl: res.reply_audio_url }]);
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
        const res = await postVoice(blob);
        setMessages((m) => [
          ...m,
          { role: 'user', content: res.transcript ?? '', audioUrl: voiceUrl },
          { role: 'assistant', content: res.reply_text, audioUrl: res.reply_audio_url },
        ]);
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

  return (
    <div className="m-screen va-screen">
      <div className="va-head">
        <button className="c-icon-btn" aria-label="Back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={18} /></button>
        <div className="va-head-text">
          <span className="va-title">Ask about your business</span>
          <span className="va-sub">Ask a question — or tell me to change something</span>
        </div>
        {messages.length > 0 && (
          <button className="c-icon-btn" aria-label="Clear conversation" onClick={clearConversation}>
            <Icons.Trash size={18} />
          </button>
        )}
      </div>

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
              <AudioBubble src={m.audioUrl} autoPlay={m.role === 'assistant' && i >= restoredCount.current} />
            )}
            {m.content && <div className="va-text">{m.content}</div>}
          </div>
        ))}
        {busy && <ThinkingBubble />}
        {error && <div className="c-error-box">{error}</div>}
      </div>

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
    </div>
  );
}
