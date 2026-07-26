import { describe, it, expect } from 'vitest';
import { normalise, matchesWakePhrase } from './wakeWord';

describe('normalise', () => {
  it('lowercases, strips punctuation and collapses whitespace', () => {
    expect(normalise('  Hey,   Northside!  ')).toBe('hey northside');
  });
});

describe('matchesWakePhrase', () => {
  const phrase = 'Northside';

  it('matches the phrase said on its own', () => {
    expect(matchesWakePhrase('northside', phrase)).toBe(true);
  });

  it('matches with a "hey" prefix', () => {
    expect(matchesWakePhrase('hey northside', phrase)).toBe(true);
  });

  it('matches other filler prefixes', () => {
    expect(matchesWakePhrase('ok northside', phrase)).toBe(true);
    expect(matchesWakePhrase('okay northside', phrase)).toBe(true);
    expect(matchesWakePhrase('hello northside', phrase)).toBe(true);
  });

  it('matches with a trailing word', () => {
    expect(matchesWakePhrase('northside barbers', phrase)).toBe(true);
  });

  it('matches the phrase mid-sentence', () => {
    expect(matchesWakePhrase('so i said northside please', phrase)).toBe(true);
  });

  it('ignores casing and punctuation', () => {
    expect(matchesWakePhrase('Hey, NORTHSIDE!', phrase)).toBe(true);
  });

  it('tolerates a single-character mishearing', () => {
    // Speech-to-text routinely drops or swaps a letter in a business name.
    expect(matchesWakePhrase('northsid', phrase)).toBe(true);
    expect(matchesWakePhrase('northsyde', phrase)).toBe(true);
  });

  it('does not match an unrelated sentence', () => {
    expect(matchesWakePhrase('what is the weather today', phrase)).toBe(false);
  });

  it('does not match a different, similar-length word', () => {
    expect(matchesWakePhrase('countryside', phrase)).toBe(false);
  });

  it('matches a multi-word phrase', () => {
    expect(matchesWakePhrase('hey northside barbers please', 'Northside Barbers')).toBe(true);
  });

  it('requires an exact match for a short phrase', () => {
    // A 1-edit tolerance on a 3-letter phrase would fire on ordinary speech.
    expect(matchesWakePhrase('zip', 'zap')).toBe(false);
    expect(matchesWakePhrase('zap', 'zap')).toBe(true);
  });

  it('never matches on an empty or whitespace phrase', () => {
    expect(matchesWakePhrase('anything at all', '')).toBe(false);
    expect(matchesWakePhrase('anything at all', '   ')).toBe(false);
  });

  it('never matches on empty heard text', () => {
    expect(matchesWakePhrase('', phrase)).toBe(false);
  });

  it('does not match when the phrase is longer than what was heard', () => {
    expect(matchesWakePhrase('north', 'Northside Barbers Downtown')).toBe(false);
  });
});

/**
 * Regression: the live "hey jarvis" failure. Owners naturally type the whole
 * spoken phrase into Settings, filler included. Stripping the filler only from
 * the heard side left a 1-word utterance being matched against a 2-word target,
 * so the wake word could never fire — not even for the exact phrase.
 */
describe('matchesWakePhrase — a phrase that itself opens with a filler', () => {
  const phrase = 'hey jarvis';

  it('matches the phrase said exactly as written', () => {
    expect(matchesWakePhrase('hey jarvis', phrase)).toBe(true);
  });

  it('matches the name on its own, without the filler', () => {
    expect(matchesWakePhrase('jarvis', phrase)).toBe(true);
  });

  it('matches mid-sentence', () => {
    expect(matchesWakePhrase('hey jarvis, read my summary', phrase)).toBe(true);
  });

  it('tolerates a mishearing of the name', () => {
    expect(matchesWakePhrase('hey jarvos', phrase)).toBe(true);
  });

  it('still rejects unrelated speech', () => {
    expect(matchesWakePhrase('hey what is the weather', phrase)).toBe(false);
  });

  it('handles a different filler on either side', () => {
    expect(matchesWakePhrase('ok jarvis', phrase)).toBe(true);
    expect(matchesWakePhrase('hey jarvis', 'ok jarvis')).toBe(true);
  });

  it('does not strip the only word, so a bare filler phrase still behaves', () => {
    // "hey" alone is a 3-char phrase: exact-match only, and it must not
    // normalise away to an empty target that matches everything.
    expect(matchesWakePhrase('anything at all', 'hey')).toBe(false);
    expect(matchesWakePhrase('hey', 'hey')).toBe(true);
  });
});
