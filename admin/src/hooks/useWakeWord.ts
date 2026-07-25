import { useEffect, useRef, useState } from 'react';
import { matchesWakePhrase } from '@/lib/wakeWord';
import { speechRecognitionCtor, type SpeechRecognitionLike } from '@/lib/speechRecognition';

/** Two wakes closer together than this are the same utterance (interim results repeat). */
const WAKE_DEBOUNCE_MS = 1500;
/** Browsers end a continuous session every so often; restart after a short beat. */
const RESTART_MS = 400;
/** Give up after this many restarts that produced nothing, rather than looping hot. */
const MAX_RESTARTS = 40;

/**
 * Listens continuously for a wake phrase and calls `onWake` when it hears it.
 *
 * Everything stays in the browser — no audio is uploaded and no assistant
 * credits are spent. The caller is responsible for setting `enabled` to false
 * while it is speaking, so the summary's own audio cannot re-trigger a wake.
 */
export function useWakeWord({ phrase, enabled, onWake }: {
  phrase: string;
  enabled: boolean;
  onWake: () => void;
}): { supported: boolean; listening: boolean; blocked: boolean } {
  const supported = speechRecognitionCtor() !== null;
  const [listening, setListening] = useState(false);
  const [blocked, setBlocked] = useState(false);

  // Keep the latest callback so a long-lived recogniser never calls a stale one.
  const onWakeRef = useRef(onWake);
  onWakeRef.current = onWake;
  const phraseRef = useRef(phrase);
  phraseRef.current = phrase;

  const recRef = useRef<SpeechRecognitionLike | null>(null);
  const lastWakeRef = useRef(0);
  const restartsRef = useRef(0);
  const timerRef = useRef<number | null>(null);

  useEffect(() => {
    const Ctor = speechRecognitionCtor();
    const active = enabled && !!Ctor && phrase.trim().length > 0;
    if (!active) { setListening(false); return; }

    let stopped = false;
    restartsRef.current = 0;
    setBlocked(false);

    const start = () => {
      if (stopped) return;
      const rec = new Ctor!();
      recRef.current = rec;
      rec.continuous = true;
      rec.interimResults = true;
      rec.lang = (typeof navigator !== 'undefined' && navigator.language) || 'en-US';

      rec.onresult = (e) => {
        const text = Array.from(e.results).map((r) => r[0].transcript).join(' ');
        if (!matchesWakePhrase(text, phraseRef.current)) return;
        const now = Date.now();
        if (now - lastWakeRef.current < WAKE_DEBOUNCE_MS) return;
        lastWakeRef.current = now;
        onWakeRef.current();
      };

      rec.onerror = (e) => {
        // A denied mic is terminal — flag it and stop, never retry in a loop.
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
          stopped = true;
          setBlocked(true);
          setListening(false);
        }
      };

      rec.onend = () => {
        if (stopped) { setListening(false); return; }
        if (restartsRef.current++ >= MAX_RESTARTS) { setListening(false); return; }
        timerRef.current = window.setTimeout(start, RESTART_MS);
      };

      try { rec.start(); setListening(true); }
      catch { setListening(false); }
    };

    start();

    // Never hold the mic open on a page or tab the owner has left.
    const onHidden = () => { if (document.hidden) { stopped = true; try { recRef.current?.stop(); } catch { /* already stopped */ } } };
    document.addEventListener('visibilitychange', onHidden);

    return () => {
      stopped = true;
      document.removeEventListener('visibilitychange', onHidden);
      if (timerRef.current != null) window.clearTimeout(timerRef.current);
      try { recRef.current?.stop(); } catch { /* already stopped */ }
      recRef.current = null;
      setListening(false);
    };
  }, [enabled, phrase]);

  return { supported, listening, blocked };
}
