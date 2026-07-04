import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { useSubscription } from '@/context/SubscriptionContext';
import TrialBanner from './TrialBanner';

vi.mock('@/context/SubscriptionContext', () => ({ useSubscription: vi.fn() }));
const mockSub = (sub: unknown) =>
  (useSubscription as unknown as ReturnType<typeof vi.fn>).mockReturnValue({ sub, loading: false, refresh: vi.fn() });

describe('TrialBanner', () => {
  it('shows the nudge in the final days of the trial', () => {
    mockSub({ status: 'trialing', days_left: 4 });
    render(<MemoryRouter><TrialBanner /></MemoryRouter>);
    expect(screen.getByText(/4 days left/i)).toBeInTheDocument();
  });

  it('stays silent early in the trial', () => {
    mockSub({ status: 'trialing', days_left: 20 });
    const { container } = render(<MemoryRouter><TrialBanner /></MemoryRouter>);
    expect(container).toBeEmptyDOMElement();
  });
});
