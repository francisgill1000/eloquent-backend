import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as leadsLib from '@/lib/leads';
import type { LeadSearchResponse } from '@/types';
import Leads from './Leads';

const EMPTY_FUNNEL = { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 };

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Leads /></ShopProvider></MemoryRouter>);
}

describe('Leads – Find pane search re-entrancy', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
  });

  it('does not fire a second search while the first is still in flight (Enter key)', async () => {
    vi.spyOn(leadsLib, 'listLeads').mockResolvedValue({ data: [], funnel: EMPTY_FUNNEL, pipelines: [], won_value: 0 });
    vi.spyOn(leadsLib, 'getLeadCredits').mockResolvedValue({ credits: 50, can_purchase: false, embedded_checkout: false, packs: [] });
    vi.spyOn(leadsLib, 'startAdSearch').mockRejectedValue(new Error('skip'));

    let resolveSearch: ((v: LeadSearchResponse) => void) | undefined;
    const search = vi.spyOn(leadsLib, 'searchLeads').mockImplementation(
      () => new Promise((resolve) => { resolveSearch = resolve; }),
    );

    setup();

    const input = await screen.findByPlaceholderText(/what & where/i);
    const user = userEvent.setup();
    await user.type(input, 'car wash');
    await user.keyboard('{Enter}');
    // Fired again while the first search's promise is still unresolved —
    // must not start a second search (would burn a second credit).
    await user.keyboard('{Enter}');

    expect(search).toHaveBeenCalledTimes(1);

    // Settle the in-flight search so its state update lands inside act(),
    // keeping teardown clean.
    await act(async () => {
      resolveSearch?.({ data: [], meta: { from_cache: true, credits: 50, searched_for: 'car wash' } });
      await Promise.resolve();
    });
  });
});
