import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useWakeWord } from './useWakeWord';

/** Minimal stand-in for the browser's SpeechRecognition. */
class FakeRecognition {
  static instances: FakeRecognition[] = [];
  continuous = false;
  interimResults = false;
  lang = '';
  started = false;
  onresult: ((e: unknown) => void) | null = null;
  onend: (() => void) | null = null;
  onerror: ((e: { error: string }) => void) | null = null;

  constructor() { FakeRecognition.instances.push(this); }
  start() { this.started = true; }
  stop() { this.started = false; this.onend?.(); }

  /** Simulate the browser reporting speech. */
  hear(transcript: string) {
    this.onresult?.({ results: [[{ transcript }]] });
  }
  fail(error: string) { this.onerror?.({ error }); }
}

const w = window as unknown as { SpeechRecognition?: unknown; webkitSpeechRecognition?: unknown };

beforeEach(() => {
  vi.useFakeTimers();
  FakeRecognition.instances = [];
  w.SpeechRecognition = FakeRecognition;
});

afterEach(() => {
  vi.useRealTimers();
  delete w.SpeechRecognition;
  delete w.webkitSpeechRecognition;
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
});
