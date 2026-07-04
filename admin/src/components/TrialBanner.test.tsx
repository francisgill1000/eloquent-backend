import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import TrialBanner from './TrialBanner';

vi.mock('@/context/SubscriptionContext', () => ({
  useSubscription: () => ({ sub: { status: 'trialing', days_left: 5 }, loading: false, refresh: vi.fn() }),
}));

describe('TrialBanner', () => {
  it('shows days left during trial', () => {
    render(<MemoryRouter><TrialBanner /></MemoryRouter>);
    expect(screen.getByText(/5 days left/i)).toBeInTheDocument();
  });
});
