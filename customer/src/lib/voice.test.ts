import { describe, it, expect } from 'vitest';
import { pickFemaleVoice, toSpeech } from './voice';

const v = (name: string, lang = 'en-US') => ({ name, lang }) as SpeechSynthesisVoice;

describe('pickFemaleVoice', () => {
  it('prefers an english voice whose name signals female', () => {
    const voices = [v('Microsoft David - English'), v('Microsoft Zira - English')];
    expect(pickFemaleVoice(voices)?.name).toBe('Microsoft Zira - English');
  });

  it('matches the explicit "Female" label', () => {
    const voices = [v('Google UK English Male', 'en-GB'), v('Google UK English Female', 'en-GB')];
    expect(pickFemaleVoice(voices)?.name).toBe('Google UK English Female');
  });

  it('prefers english over a female-named non-english voice', () => {
    const voices = [v('Amelie', 'fr-FR'), v('Daniel', 'en-GB')];
    expect(pickFemaleVoice(voices)?.name).toBe('Daniel');
  });

  it('falls back to the first english voice when none look female', () => {
    const voices = [v('Voice A', 'en-US'), v('Voice B', 'fr-FR')];
    expect(pickFemaleVoice(voices)?.name).toBe('Voice A');
  });

  it('returns undefined for an empty list', () => {
    expect(pickFemaleVoice([])).toBeUndefined();
  });
});

describe('toSpeech', () => {
  it('strips a leading emoji run', () => {
    expect(toSpeech('🎉 Booking confirmed!')).toBe('Booking confirmed!');
  });

  it('replaces a payment link with a spoken cue instead of reading the URL', () => {
    expect(toSpeech('Your booking is confirmed. Pay here: https://pay.ziina.com/abc123'))
      .toBe('Your booking is confirmed. Pay here: the link below');
  });

  it('handles a bare www link too', () => {
    expect(toSpeech('Visit www.example.com/pay?x=1 now')).toBe('Visit the link below now');
  });

  it('leaves plain text unchanged', () => {
    expect(toSpeech('What time would you like to come in?')).toBe('What time would you like to come in?');
  });

  it('strips the [[services]] marker so it is not read aloud', () => {
    expect(toSpeech('Here are our services:\n[[services]]')).toBe('Here are our services:');
    expect(toSpeech('Sure! [[services]] Let me know which one.')).toBe('Sure! Let me know which one.');
  });

  it('returns empty string for null/empty input', () => {
    expect(toSpeech(null)).toBe('');
    expect(toSpeech('')).toBe('');
  });
});
