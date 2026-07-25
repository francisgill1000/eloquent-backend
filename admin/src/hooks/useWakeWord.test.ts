import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useWakeWord } from './useWakeWord';

/**
 * Minimal stand-in for the browser's SpeechRecognition. Faithful to the two
 * traits that matter here: a continuous session's `results` accumulate for
 * its whole life (finalized entries are never removed), and `stop()` ends
 * the session asynchronously — it does not itself invoke `onend`, matching
 * the real API.
 */
class FakeRecognition {
  static instances: FakeRecognition[] = [];
  continuous = false;
  interimResults = false;
  lang = '';
  started = false;
  results: { transcript: string }[][] = [];
  onresult: ((e: { results: ArrayLike<ArrayLike<{ transcript: string }>>; resultIndex: number }) => void) | null = null;
  onend: (() => void) | null = null;
  onerror: ((e: { error: string }) => void) | null = null;

  constructor() { FakeRecognition.instances.push(this); }
  start() { this.started = true; }
  stop() { this.started = false; }

  /** Simulate the browser reporting a new (accumulating) result. */
  hear(transcript: string) {
    const resultIndex = this.results.length;
    this.results.push([{ transcript }]);
    this.onresult?.({ results: this.results, resultIndex });
  }
  fail(error: string) { this.onerror?.({ error }); }
}

const w = window as unknown as { SpeechRecognition?: unknown; webkitSpeechRecognition?: unknown };

/** Fake the page's visibility so the tab-hide/resume path can be driven directly. */
function setHidden(hidden: boolean) {
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
  document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
  vi.useFakeTimers();
  FakeRecognition.instances = [];
  w.SpeechRecognition = FakeRecognition;
});

afterEach(() => {
  vi.useRealTimers();
  delete w.SpeechRecognition;
  delete w.webkitSpeechRecognition;
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
});

describe('useWakeWord', () => {
  it('reports unsupported when the browser has no speech recognition', () => {
    delete w.SpeechRecognition;
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    expect(result.current.supported).toBe(false);
    expect(result.current.listening).toBe(false);
  });

  it('starts listening when enabled', () => {
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    expect(result.current.supported).toBe(true);
    expect(FakeRecognition.instances[0].started).toBe(true);
    expect(result.current.listening).toBe(true);
  });

  it('does not start when disabled', () => {
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: false, onWake: vi.fn() }));
    expect(FakeRecognition.instances.length).toBe(0);
  });

  it('does not start with an empty phrase', () => {
    renderHook(() => useWakeWord({ phrase: '', enabled: true, onWake: vi.fn() }));
    expect(FakeRecognition.instances.length).toBe(0);
  });

  it('calls onWake when it hears the phrase', () => {
    const onWake = vi.fn();
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake }));
    act(() => { FakeRecognition.instances[0].hear('hey northside'); });
    expect(onWake).toHaveBeenCalledTimes(1);
  });

  it('ignores speech that is not the phrase', () => {
    const onWake = vi.fn();
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake }));
    act(() => { FakeRecognition.instances[0].hear('what is the weather'); });
    expect(onWake).not.toHaveBeenCalled();
  });

  it('debounces repeated interim results into a single wake', () => {
    const onWake = vi.fn();
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake }));
    act(() => {
      FakeRecognition.instances[0].hear('hey northside');
      FakeRecognition.instances[0].hear('hey northside');
    });
    expect(onWake).toHaveBeenCalledTimes(1);
  });

  it('restarts after the browser ends the session', () => {
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    act(() => { FakeRecognition.instances[0].onend?.(); });
    act(() => { vi.advanceTimersByTime(500); });
    expect(FakeRecognition.instances.length).toBe(2);
    expect(FakeRecognition.instances[1].started).toBe(true);
  });

  it('stops and reports blocked when permission is denied, without retrying', () => {
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    act(() => { FakeRecognition.instances[0].fail('not-allowed'); });
    act(() => { vi.advanceTimersByTime(5000); });
    expect(result.current.blocked).toBe(true);
    expect(result.current.listening).toBe(false);
    expect(FakeRecognition.instances.length).toBe(1);
  });

  it('stops listening when it becomes disabled', () => {
    const { result, rerender } = renderHook(
      ({ enabled }) => useWakeWord({ phrase: 'Northside', enabled, onWake: vi.fn() }),
      { initialProps: { enabled: true } },
    );
    expect(result.current.listening).toBe(true);
    rerender({ enabled: false });
    expect(result.current.listening).toBe(false);
  });

  it('stops listening on unmount', () => {
    const { unmount } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    const rec = FakeRecognition.instances[0];
    unmount();
    expect(rec.started).toBe(false);
  });

  // Finding 1: a continuous session's `results` accumulate for its whole
  // life, so a naive full-history join would re-match an old, already
  // finalized wake on every later, unrelated result.
  it('does not re-fire the wake on later unrelated speech in the same session', () => {
    const onWake = vi.fn();
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake }));
    act(() => { FakeRecognition.instances[0].hear('hey northside'); });
    expect(onWake).toHaveBeenCalledTimes(1);

    // The earlier match is still sitting in `results` (the fake never
    // removes finalized entries), and we're well past the debounce window.
    act(() => { FakeRecognition.instances[0].hear('what is the weather'); });
    act(() => { vi.advanceTimersByTime(2000); });
    expect(onWake).toHaveBeenCalledTimes(1);
  });

  // Finding 2: a superseded instance's `onend` can arrive after the hook has
  // already moved on to a new recogniser (real `stop()` ends asynchronously).
  it('ignores a stale onend from a superseded instance after enabled toggles off then on', () => {
    const { result, rerender } = renderHook(
      ({ enabled }) => useWakeWord({ phrase: 'Northside', enabled, onWake: vi.fn() }),
      { initialProps: { enabled: true } },
    );
    const first = FakeRecognition.instances[0];

    rerender({ enabled: false });
    rerender({ enabled: true });
    expect(FakeRecognition.instances.length).toBe(2);
    expect(result.current.listening).toBe(true);

    // The old instance's onend arrives late, after the hook already started
    // a fresh recogniser — it must not clobber the live session's state.
    act(() => { first.onend?.(); });
    expect(result.current.listening).toBe(true);
    expect(FakeRecognition.instances[1].started).toBe(true);
  });

  // Finding 3: pausing for a hidden tab must be resumable, not terminal.
  it('pauses while the tab is hidden and resumes when it becomes visible again', () => {
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    expect(result.current.listening).toBe(true);

    act(() => { setHidden(true); });
    expect(result.current.listening).toBe(false);
    expect(FakeRecognition.instances[0].started).toBe(false);

    act(() => { setHidden(false); });
    expect(result.current.listening).toBe(true);
    expect(FakeRecognition.instances.length).toBe(2);
    expect(FakeRecognition.instances[1].started).toBe(true);
  });

  it('does not resume on becoming visible after the mic was blocked', () => {
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    act(() => { FakeRecognition.instances[0].fail('not-allowed'); });
    expect(result.current.blocked).toBe(true);

    act(() => { setHidden(true); });
    act(() => { setHidden(false); });
    expect(FakeRecognition.instances.length).toBe(1);
    expect(result.current.blocked).toBe(true);
  });

  // New finding: a restart timer scheduled while still visible must not
  // escape a hide that happens before it fires — otherwise a stray
  // recogniser starts while the tab is hidden, and the show branch then
  // starts a second one on top of it.
  it('cancels a pending restart timer when the tab is hidden before it fires', () => {
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));

    // A natural onend while still visible schedules a restart via timerRef.
    act(() => { FakeRecognition.instances[0].onend?.(); });
    // Hide before that restart timer fires.
    act(() => { setHidden(true); });

    // Advance well past RESTART_MS: nothing should have started while hidden.
    act(() => { vi.advanceTimersByTime(2000); });
    expect(FakeRecognition.instances.length).toBe(1);
    expect(result.current.listening).toBe(false);

    // Coming back resumes exactly once — not a second time on top of a stray start.
    act(() => { setHidden(false); });
    expect(FakeRecognition.instances.length).toBe(2);
    expect(FakeRecognition.instances[1].started).toBe(true);
    expect(result.current.listening).toBe(true);
  });
});
