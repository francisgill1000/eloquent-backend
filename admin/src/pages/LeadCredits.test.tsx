import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as leadsLib from '@/lib/leads';
import LeadCredits from './LeadCredits';

const PACKS = [
  { id: 1, name: 'Starter', credits: 200, price_fils: 19900 },
  { id: 2, name: 'Growth', credits: 500, price_fils: 44900 },
];

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><LeadCredits /></ShopProvider></MemoryRouter>);
}

describe('LeadCredits', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
  });

  it('shows the balance and buyable packs for a self-serve shop', async () => {
    vi.spyOn(leadsLib, 'getLeadCredits').mockResolvedValue({
      credits: 110, can_purchase: true, embedded_checkout: false, packs: PACKS,
    });
    const checkout = vi.spyOn(leadsLib, 'startPackCheckout')
      .mockResolvedValue({ redirect_url: 'https://pay.ziina.com/x', embedded_url: null, intent_id: null });

    setup();

    expect(await screen.findByText('110')).toBeInTheDocument();
    // Packs render as buy buttons (not WhatsApp links).
    const buy = await screen.findByRole('button', { name: /200\s*credits/i });
    await userEvent.setup().click(buy);
    expect(checkout).toHaveBeenCalledWith(1);
  });

  it('does not start a second checkout on a same-tick double-click', async () => {
    vi.spyOn(leadsLib, 'getLeadCredits').mockResolvedValue({
      credits: 110, can_purchase: true, embedded_checkout: false, packs: PACKS,
    });
    let resolveCheckout: ((v: { redirect_url: string | null; embedded_url: string | null; intent_id: string | null }) => void) | undefined;
    const checkout = vi.spyOn(leadsLib, 'startPackCheckout').mockImplementation(
      () => new Promise((resolve) => { resolveCheckout = resolve; }),
    );

    setup();

    const buy = await screen.findByRole('button', { name: /200\s*credits/i });
    // Two raw click events in the same tick — no await between them — is what
    // a very fast real double-click looks like: both fire before React has a
    // chance to commit the `disabled` update from the first click's setState.
    act(() => {
      fireEvent.click(buy);
      fireEvent.click(buy);
    });

    expect(checkout).toHaveBeenCalledTimes(1);

    await act(async () => {
      resolveCheckout?.({ redirect_url: 'https://pay.ziina.com/x', embedded_url: null, intent_id: null });
    });
  });

  it('shows a WhatsApp top-up fallback when the shop cannot self-serve', async () => {
    vi.spyOn(leadsLib, 'getLeadCredits').mockResolvedValue({
      credits: 4, can_purchase: false, embedded_checkout: false, packs: PACKS,
    });

    setup();

    expect(await screen.findByRole('link', { name: /message us to top up/i })).toBeInTheDocument();
    // No buy buttons when self-serve is off.
    expect(screen.queryByRole('button', { name: /credits/i })).toBeNull();
  });
});
