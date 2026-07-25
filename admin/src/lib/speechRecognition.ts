/**
 * The browser's speech recognition API, narrowed to what this app uses and
 * feature-detected in one place. Chrome exposes it prefixed; Firefox and older
 * iOS do not expose it at all.
 */
export type SpeechRecognitionLike = {
  continuous: boolean;
  interimResults: boolean;
  lang: string;
  start(): void;
  stop(): void;
  onresult: ((e: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null;
  onend: (() => void) | null;
  onerror: ((e: { error: string }) => void) | null;
};

export type SpeechRecognitionCtor = new () => SpeechRecognitionLike;

/** The constructor, or null where the browser has no speech recognition. */
export function speechRecognitionCtor(): SpeechRecognitionCtor | null {
  if (typeof window === 'undefined') return null;
  const w = window as unknown as {
    SpeechRecognition?: SpeechRecognitionCtor;
    webkitSpeechRecognition?: SpeechRecognitionCtor;
  };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}
