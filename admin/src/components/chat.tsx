import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';

// Turn any booking reference (BK00042) in a message into a link to that
// booking's detail page — the reference's digits are the booking id. Pass
// { linkifyRefs: false } on public/customer surfaces where there's no auth to
// open the booking page; the reference then renders as plain text.
export function renderContent(text: string, opts?: { linkifyRefs?: boolean }): ReactNode {
  const linkify = opts?.linkifyRefs !== false;
  return text.split(/\b(BK\d{4,})\b/g).map((part, i) =>
    /^BK\d{4,}$/.test(part)
      ? (linkify
          ? <Link key={i} className="va-ref" to={`/booking/${parseInt(part.slice(2), 10)}`}>{part}</Link>
          : <span key={i}>{part}</span>)
      : part,
  );
}

// Rotating status words shown while the assistant is working.
const THINKING_WORDS = [
  'Thinking',
  'Crunching the numbers',
  'Checking your books',
  'Looking into it',
  'Consulting your data',
  'Working it out',
  'Almost there',
];

export function ThinkingBubble() {
  const [i, setI] = useState(0);
  useEffect(() => {
    const id = setInterval(() => setI((n) => (n + 1) % THINKING_WORDS.length), 1500);
    return () => clearInterval(id);
  }, []);
  return (
    <div className="va-bubble va-ai va-thinking">
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
 * track, and elapsed time. Auto-plays once on mount when autoPlay is set.
 */
export function AudioBubble({ src, autoPlay = false, onEnded }: { src: string; autoPlay?: boolean; onEnded?: () => void }) {
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
