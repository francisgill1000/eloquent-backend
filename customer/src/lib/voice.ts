// Names that signal a female voice across the common platforms (iOS/macOS,
// Windows/Edge, Android/Chrome). Used to pick a female voice for the assistant.
const FEMALE_HINTS =
  /(female|woman|samantha|victoria|karen|tessa|susan|catherine|serena|moira|fiona|amelia|sonia|aria|jenny|libby|sara|zira|hazel|google uk english female)/i;

/**
 * Choose a female English voice from the browser's available voices. Prefers an
 * English voice whose name signals female; otherwise any "female"-labelled
 * voice; otherwise the first English voice; otherwise the first voice at all.
 */
export function pickFemaleVoice(voices: SpeechSynthesisVoice[]): SpeechSynthesisVoice | undefined {
  const english = voices.filter((v) => v.lang?.toLowerCase().startsWith('en'));
  const pool = english.length ? english : voices;
  return (
    pool.find((v) => FEMALE_HINTS.test(v.name)) ??
    pool.find((v) => /female/i.test(v.name)) ??
    pool[0]
  );
}

/**
 * Marker the assistant emits to tell the app to render the shop's services
 * list. It is stripped from both the spoken text and the visible bubble text
 * (the app renders a card in its place).
 */
export const SERVICES_TOKEN = '[[services]]';

/**
 * Turn a chat message body into text suitable for speaking aloud:
 * - drops a leading emoji/symbol run (so "🎉 Booking…" reads as "Booking…"),
 * - removes the [[services]] marker (the list is shown, not read out),
 * - replaces any URL (e.g. a payment link) with a short spoken cue so the full
 *   link is never read out — the assistant guides the user to the tappable link
 *   in the thread instead of dictating it.
 */
export function toSpeech(body: string | null | undefined): string {
  return (body ?? '')
    .split(SERVICES_TOKEN).join(' ')
    .replace(/^[^\p{L}\d]+/u, '')
    .replace(/\b(?:https?:\/\/|www\.)\S+/gi, 'the link below')
    .replace(/\s+/g, ' ')
    .trim();
}
