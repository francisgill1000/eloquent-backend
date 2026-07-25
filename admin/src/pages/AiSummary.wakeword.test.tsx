import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import AiSummary from './AiSummary';

vi.mock('@/context/ShopContext', () => ({ useShop: () => ({ shop: { id: 1, name: 'Northside Barbers' } }) }));
vi.mock('@/lib/simulation', () => ({ speak: vi.fn().mockResolvedValue('blob:fake') }));

// jsdom has no real media pipeline; the page constructs a real HTMLAudioElement
// and calls play()/pause() on it, which jsdom logs as "not implemented" and
// throws. Stub both once so the "plays the summary" test can exercise the
// actual toggle logic without that console noise.
HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined);
HTMLMediaElement.prototype.pause = vi.fn();
// jsdom also has no Blob URL registry, so URL.revokeObjectURL — called from
// the real `onended` handler — doesn't exist at all. Stub it for the same
// reason as play/pause above.
URL.revokeObjectURL = vi.fn();

// The self-trigger-guard test needs a handle on the Audio element the page
// constructs internally, so it can drive the end-of-playback path (onended)
// directly rather than waiting on real audio to actually play.
let lastAudioEl: HTMLAudioElement | null = null;
const RealAudio = window.Audio;
vi.stubGlobal('Audio', function (...args: ConstructorParameters<typeof RealAudio>) {
  const el = new RealAudio(...args);
  lastAudioEl = el;
  return el;
});

const getAiInsights = vi.fn();
const getAiSummaryHistory = vi.fn();
vi.mock('@/lib/aiInsights', () => ({
  getAiInsights: (...a: unknown[]) => getAiInsights(...a),
  getAiSummaryHistory: (...a: unknown[]) => getAiSummaryHistory(...a),
}));

const getWakeWord = vi.fn();
vi.mock('@/lib/wakeWordApi', () => ({ getWakeWord: () => getWakeWord() }));

// Capture the hook's arguments so the test can fire a wake without a real mic.
let hookArgs: { phrase: string; enabled: boolean; onWake: () => void } | null = null;
const hookReturn = { supported: true, listening: true, blocked: false };
vi.mock('@/hooks/useWakeWord', () => ({
  useWakeWord: (opts: { phrase: string; enabled: boolean; onWake: () => void }) => {
    hookArgs = opts;
    return hookReturn;
  },
}));

const ok = { state: 'ok', summary: 'S', patterns: [], recommendations: [], message: '', generated_at: '', cached: false };

beforeEach(() => {
  hookArgs = null;
  hookReturn.supported = true;
  hookReturn.blocked = false;
  localStorage.clear();
  getAiInsights.mockReset().mockResolvedValue(ok);
  getAiSummaryHistory.mockReset().mockResolvedValue({ data: [], has_more: false });
  getWakeWord.mockReset().mockResolvedValue(
    { phrase: null, effective_phrase: 'Northside Barbers', using_custom: false },
  );
});

afterEach(() => { localStorage.clear(); });

describe('AiSummary wake word', () => {
  it('listens for the shop wake phrase by default', async () => {
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs?.phrase).toBe('Northside Barbers'));
    expect(hookArgs?.enabled).toBe(true);
  });

  it('uses the saved custom phrase when there is one', async () => {
    getWakeWord.mockResolvedValue({ phrase: 'Northside', effective_phrase: 'Northside', using_custom: true });
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs?.phrase).toBe('Northside'));
  });

  it('falls back to the shop name when the request fails', async () => {
    getWakeWord.mockRejectedValue(new Error('offline'));
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs?.phrase).toBe('Northside Barbers'));
  });

  it('plays the summary when the wake phrase is heard, and mutes the mic until it stops', async () => {
    const { speak } = await import('@/lib/simulation');
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs).not.toBeNull());
    await waitFor(() => expect(screen.getByLabelText(/play summary/i)).toBeEnabled());
    expect(hookArgs?.enabled).toBe(true);

    // onWake fires a real state update outside React's event system (there's no
    // DOM event here — the mocked hook calls it directly), so wrap it in act and
    // flush a macrotask afterwards to let the toggle's internal await chain
    // (speak → new Audio → play) settle before the test ends.
    await act(async () => {
      hookArgs!.onWake();
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    await waitFor(() => expect(speak).toHaveBeenCalled());

    // The self-trigger guard: while the summary's own audio plays, listening
    // must be off, or that audio would be heard as another wake and loop forever.
    await waitFor(() => expect(hookArgs?.enabled).toBe(false));

    // Drive the audio element's end-of-playback path directly — jsdom cannot
    // really play audio — and confirm listening resumes once status is idle again.
    await act(async () => {
      lastAudioEl?.onended?.(new Event('ended'));
    });
    await waitFor(() => expect(hookArgs?.enabled).toBe(true));
  });

  it('does not enable listening before there is a summary to play', async () => {
    // Never resolves, so `data` stays null and spokenText stays '' — this
    // exercises the guard's `!!spokenText` clause on its own.
    getAiInsights.mockReturnValue(new Promise(() => {}));
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs).not.toBeNull());
    expect(hookArgs?.enabled).toBe(false);
  });

  it('hides the Listen toggle when speech recognition is unsupported', async () => {
    hookReturn.supported = false;
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs).not.toBeNull());
    expect(screen.queryByRole('switch', { name: /listen/i })).toBeNull();
  });

  it('turning the toggle off disables listening and is remembered for the device', async () => {
    const { unmount } = render(<AiSummary />);
    await waitFor(() => expect(hookArgs?.enabled).toBe(true));

    fireEvent.click(screen.getByRole('switch', { name: /listen/i }));
    await waitFor(() => expect(hookArgs?.enabled).toBe(false));
    unmount();

    render(<AiSummary />);
    await waitFor(() => expect(hookArgs).not.toBeNull());
    expect(hookArgs?.enabled).toBe(false);
  });

  it('shows a Mic blocked note when permission is denied', async () => {
    hookReturn.blocked = true;
    render(<AiSummary />);
    expect(await screen.findByText(/mic blocked/i)).toBeInTheDocument();
  });
});
