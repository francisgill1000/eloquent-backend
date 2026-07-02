import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { postText, postVoice, type AssistantTurn } from '@/lib/assistant';
import { useRecorder } from '@/hooks/useRecorder';

type Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null };

const STORAGE_KEY = 'va-conversation';

/**
 * Restore the saved conversation. Blob URLs from a previous session (the owner's
 * own recorded notes) are revoked once the page unloads, so we drop them on load
 * while keeping the transcript text — the player would otherwise be dead.
 */
function loadSaved(): Msg[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const arr = JSON.parse(raw) as Msg[];
    return Array.isArray(arr)
      ? arr.map((m) => (m.audioUrl?.startsWith('blob:') ? { ...m, audioUrl: null } : m))
      : [];
  } catch {
    return [];
  }
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
  const [messages, setMessages] = useState<Msg[]>(loadSaved);
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState('');
  const [error, setError] = useState('');
  const { recording, start, stop, supported } = useRecorder();
  const threadRef = useRef<HTMLDivElement>(null);
  // Messages restored from storage should not auto-play their audio on mount;
  // only notes added during this session do. This is the count present at load.
  const restoredCount = useRef(messages.length);

  // Keep the latest message in view as the conversation grows.
  useEffect(() => {
    threadRef.current?.scrollTo?.({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, busy]);

  // Persist the conversation so it survives navigation and reloads. Blob URLs
  // are session-local, so we strip them before saving and keep the transcript.
  useEffect(() => {
    try {
      const saved = messages.map((m) => (m.audioUrl?.startsWith('blob:') ? { ...m, audioUrl: null } : m));
      localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
    } catch {
      /* storage full or unavailable — the conversation just won't persist */
    }
  }, [messages]);

  function clearConversation() {
    setMessages([]); // the persist effect then writes an empty conversation
    setError('');
    restoredCount.current = 0;
  }

  // Text-only view of the conversation to send as context (the server appends
  // the new user message itself, so we send the prior turns only). Blank-content
  // turns — left behind by a failed voice transcription — are dropped: Anthropic
  // rejects empty-content messages (400), which would poison every later turn.
  const historyToSend = (): AssistantTurn[] =>
    messages.filter((m) => m.content.trim() !== '').map((m) => ({ role: m.role, content: m.content }));

  async function send(text: string) {
    if (!text.trim() || busy) return;
    setBusy(true); setError('');
    const hist = historyToSend();
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setDraft('');
    try {
      const res = await postText(text, hist);
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
      const hist = historyToSend();
      try {
        const res = await postVoice(blob, hist);
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
          <span className="va-sub">Speak or type — answers come back as voice</span>
        </div>
        {messages.length > 0 && (
          <button className="c-icon-btn" aria-label="Clear conversation" onClick={clearConversation}>
            <Icons.Trash size={18} />
          </button>
        )}
      </div>

      <div className="va-thread" ref={threadRef}>
        {messages.length === 0 && !busy && (
          <div className="va-empty">
            <div className="va-empty-mic"><Icons.Mic size={26} /></div>
            <p className="va-hint">Tap the mic and ask, e.g.<br />"How much did I make this month?"</p>
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
        {busy && <div className="va-bubble va-ai va-typing">…</div>}
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
