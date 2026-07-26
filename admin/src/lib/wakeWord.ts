/**
 * Wake-phrase matching for the AI Summary page. Pure and DOM-free — the browser
 * speech API lives in hooks/useWakeWord.ts, so this module can be tested on its
 * own and carries the feature's real correctness coverage.
 *
 * Speech-to-text routinely mishears business names, so matching is fuzzy: an
 * edit budget scaled to the phrase length. Short phrases get a budget of zero,
 * because one allowed edit on a three-letter word fires on ordinary chatter.
 */

/** Optional openers a speaker naturally puts in front of the phrase. */
const FILLERS = ['hey', 'hi', 'hello', 'ok', 'okay'];

/** Lowercase, strip punctuation, collapse whitespace. */
export function normalise(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Classic Levenshtein distance between two normalised strings. */
function distance(a: string, b: string): number {
  if (a === b) return 0;
  if (!a.length) return b.length;
  if (!b.length) return a.length;

  let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const row = [i];
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      row[j] = Math.min(row[j - 1] + 1, prev[j] + 1, prev[j - 1] + cost);
    }
    prev = row;
  }
  return prev[b.length];
}

/** Edit budget: one per five characters, capped at three, none under five. */
function tolerance(phrase: string): number {
  return Math.min(Math.floor(phrase.length / 5), 3);
}

/**
 * Normalise, then drop one leading filler.
 *
 * This runs on BOTH sides. Owners naturally type the whole spoken phrase into
 * Settings — "hey jarvis", not "jarvis" — so stripping the filler only from
 * what was heard left a one-word utterance being compared against a two-word
 * target, and the wake word could never fire, not even for the exact phrase.
 *
 * A phrase that is nothing but a filler keeps its single word: reducing "hey"
 * to an empty target would match every utterance.
 */
function coreWords(text: string): string[] {
  const words = normalise(text).split(' ').filter(Boolean);
  return words.length > 1 && FILLERS.includes(words[0]) ? words.slice(1) : words;
}

/**
 * True when `heard` contains the wake phrase, allowing an optional filler
 * opener on either side and a small number of mishearings.
 */
export function matchesWakePhrase(heard: string, phrase: string): boolean {
  const targetWords = coreWords(phrase);
  const target = targetWords.join(' ');
  if (!target) return false;

  const words = coreWords(heard);
  if (!words.length) return false;

  const span = targetWords.length;
  const budget = tolerance(target);

  // Slide a window of the phrase's word count over the heard words. Also try a
  // window one word longer, so "northside barbers" still matches "Northside"
  // when the extra word is short enough to fall inside the edit budget.
  for (const size of [span, span + 1]) {
    for (let i = 0; i + size <= words.length; i++) {
      const window = words.slice(i, i + size).join(' ');
      if (distance(window, target) <= budget) return true;
    }
  }
  return false;
}
