import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import AiSummary from './AiSummary';

vi.mock('@/context/ShopContext', () => ({ useShop: () => ({ shop: { id: 1, name: 'Test' } }) }));
vi.mock('@/lib/simulation', () => ({ speak: vi.fn() }));

const getAiInsights = vi.fn();
const getAiSummaryHistory = vi.fn();
vi.mock('@/lib/aiInsights', () => ({
  getAiInsights: (...a: unknown[]) => getAiInsights(...a),
  getAiSummaryHistory: (...a: unknown[]) => getAiSummaryHistory(...a),
}));

const ok = {
  state: 'ok', summary: 'S', patterns: [], recommendations: [],
  message: '', generated_at: '', cached: false,
};

beforeEach(() => {
  getAiInsights.mockReset().mockResolvedValue(ok);
  getAiSummaryHistory.mockReset().mockResolvedValue({ data: [], has_more: false });
});

describe('AiSummary period selector', () => {
  it('defaults to the rolling30 period on first load', async () => {
    render(<AiSummary />);
    await waitFor(() => expect(getAiInsights).toHaveBeenCalled());
    const [, , , , period] = getAiInsights.mock.calls[0];
    expect(period).toBe('rolling30');
  });

  it('switches to weekly and refetches with period=week + loads week history', async () => {
    render(<AiSummary />);
    await waitFor(() => expect(getAiInsights).toHaveBeenCalled());

    fireEvent.click(screen.getByRole('button', { name: /weekly/i }));

    await waitFor(() => {
      const lastCall = getAiInsights.mock.calls.at(-1)!;
      expect(lastCall[4]).toBe('week');
    });
    expect(getAiSummaryHistory).toHaveBeenCalledWith(1, 'week', expect.anything());
  });
});
