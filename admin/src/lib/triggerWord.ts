// The voice "wake word" that auto-triggers actions (for now, Play Summary).
// Stored client-side so it's available across the app without a backend call.
const KEY = 'ai_trigger_word';
export const DEFAULT_TRIGGER = 'hey felix';

export function getTriggerWord(): string {
  try {
    const v = (localStorage.getItem(KEY) || '').trim();
    return v || DEFAULT_TRIGGER;
  } catch {
    return DEFAULT_TRIGGER;
  }
}

export function setTriggerWord(w: string): void {
  try {
    localStorage.setItem(KEY, w);
  } catch {
    /* storage unavailable — ignore */
  }
}
