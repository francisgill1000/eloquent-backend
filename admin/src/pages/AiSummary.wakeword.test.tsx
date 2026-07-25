import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import AiSummary from './AiSummary';

vi.mock('@/context/ShopContext', () => ({ useShop: () => ({ shop: { id: 1, name: 'Northside Barbers' } }) }));
vi.mock('@/lib/simulation', () => ({ speak: vi.fn().mockResolvedValue('blob:fake') }));

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

  it('plays the summary when the wake phrase is heard', async () => {
    const { speak } = await import('@/lib/simulation');
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs).not.toBeNull());
    await waitFor(() => expect(screen.getByLabelText(/play summary/i)).toBeEnabled());
    hookArgs!.onWake();
    await waitFor(() => expect(speak).toHaveBeenCalled());
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
