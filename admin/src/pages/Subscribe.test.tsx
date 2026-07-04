import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as subLib from '@/lib/subscription';
import Subscribe from './Subscribe';

vi.mock('@/context/SubscriptionContext', () => ({
  useSubscription: () => ({
    sub: {
      status: 'expired', plan: null, access_until: null, trial_ends_at: null, days_left: 0,
      prices: { monthly: 14900, annual: 100000 },
    },
    loading: false,
    refresh: vi.fn(),
  }),
}));

describe('Subscribe', () => {
  beforeEach(() => { vi.restoreAllMocks(); });

  it('shows both plans and starts checkout for the chosen plan', async () => {
    const spy = vi.spyOn(subLib, 'startCheckout').mockResolvedValue({ redirect_url: 'https://pay/x', intent_id: 'pi' });
    render(<MemoryRouter><Subscribe /></MemoryRouter>);
    expect(screen.getByText(/149/)).toBeInTheDocument();
    expect(screen.getByText(/1,000/)).toBeInTheDocument();
    const user = (await import('@testing-library/user-event')).default.setup();
    await user.click(screen.getByRole('button', { name: /choose monthly/i }));
    expect(spy).toHaveBeenCalledWith('monthly');
  });
});
