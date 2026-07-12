import { useEffect, useRef } from 'react';

// Minimal typings for the Web Speech API (not in the standard DOM lib).
interface SRAlternative { transcript: string }
interface SRResult { readonly length: number; readonly [i: number]: SRAlternative }
interface SRResultList { readonly length: number; readonly [i: number]: SRResult }
interface SREvent { resultIndex: number; results: SRResultList }
interface SpeechRec {
  continuous: boolean;
  interimResults: boolean;
  lang: string;
  onresult: ((e: SREvent) => void) | null;
  onend: (() => void) | null;
  onerror: (() => void) | null;
  start(): void;
  stop(): void;
}
type SRCtor = new () => SpeechRec;

function ctor(): SRCtor | undefined {
  const w = window as unknown as { SpeechRecognition?: SRCtor; webkitSpeechRecognition?: SRCtor };
  return w.SpeechRecognition || w.webkitSpeechRecognition;
}

/** Browsers with the Web Speech API (Chrome, Edge). */
export function wakeWordSupported(): boolean {
  return !!ctor();
}

const norm = (s: string) => s.toLowerCase().replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();

/**
 * While `enabled`, continuously listen for `trigger` and call onTrigger when
 * heard (with a 4s cooldown so one utterance fires once). No-op without support.
 */
export function useWakeWord(enabled: boolean, trigger: string, onTrigger: () => void): void {
  const cb = useRef(onTrigger);
  cb.current = onTrigger;

  useEffect(() => {
    if (!enabled) return;
    const Ctor = ctor();
    const target = norm(trigger);
    if (!Ctor || !target) return;

    const rec = new Ctor();
    rec.continuous = true;
    rec.interimResults = true;
    rec.lang = 'en-US';
    let stopped = false;
    let cooldownUntil = 0;

    rec.onresult = (e) => {
      const now = Date.now();
      if (now < cooldownUntil) return;
      for (let i = e.resultIndex; i < e.results.length; i++) {
        if (norm(e.results[i][0].transcript).includes(target)) {
          cooldownUntil = now + 4000;
          cb.current();
          break;
        }
      }
    };
    // Recognition auto-stops after silence; restart it while still enabled.
    rec.onend = () => { if (!stopped) { try { rec.start(); } catch { /* already running */ } } };
    rec.onerror = () => { /* transient — onend will restart */ };
    try { rec.start(); } catch { /* ignore */ }

    return () => { stopped = true; try { rec.stop(); } catch { /* ignore */ } };
  }, [enabled, trigger]);
}
