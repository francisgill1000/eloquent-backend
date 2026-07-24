import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as lib from '@/lib/huntInsights';
import type { HuntInsights as Data } from '@/lib/huntInsights';
import * as aiLib from '@/lib/aiInsights';
import HuntInsights from './HuntInsights';

function payload(over: Partial<Data> = {}): Data {
  return {
    range: { from: '2026-07-01', to: '2026-07-03' },
    summary: {
      range: { from: '2026-07-01', to: '2026-07-03' },
      new_leads: 12,
      pipeline: { new: 40, sent: 12, followup: 8, replied: 5, demo: 3, won: 4, pass: 6 },
      total_leads: 78,
      moved: { new: 0, sent: 5, followup: 3, replied: 2, demo: 1, won: 2, pass: 1 },
      won: 4,
      won_value: 9000,
      won_value_recurring: 6000,
      won_value_one_off: 3000,
      mrr_won: 1000,
      credits_used: 18,
      searches: 18,
    },
    daily: [
      { date: '2026-07-01', leads: 5, won: 1, won_value: 2000 },
      { date: '2026-07-02', leads: 4, won: 0, won_value: 0 },
      { date: '2026-07-03', leads: 3, won: 3, won_value: 7000 },
    ],
    attention: { followups_overdue: 6, followups_today: 3, stale: 11, unassigned: 24 },
    agents: [
      { id: 3, name: 'Sara', leads: 41, won: 3, won_value: 7000 },
      { id: 4, name: 'Omar', leads: 12, won: 1, won_value: 2000 },
    ],
    credits: { balance: 120, used: 18, searches: 18 },
    ...over,
  };
}

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme', modules: ['leads'] });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><HuntInsights /></ShopProvider></MemoryRouter>);
}

describe('HuntInsights', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
    // The embedded AI card auto-fetches on mount; keep it off the network.
    vi.spyOn(aiLib, 'getAiInsights').mockResolvedValue({
      state: 'low_data', summary: '', patterns: [], recommendations: [],
      message: 'Not enough activity yet.', generated_at: '', cached: false,
    });
  });

  it('shows the four headline KPIs', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('New leads')).toBeInTheDocument();
    expect(screen.getByText('Deals won')).toBeInTheDocument();
    expect(screen.getByText('Won value')).toBeInTheDocument();
    expect(screen.getByText('MRR won')).toBeInTheDocument();
    // Won value renders as a formatted AED amount.
    expect(screen.getByText('AED 9,000')).toBeInTheDocument();
  });

  it('links each attention chip to the matching filtered list', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByRole('link', { name: /6 Overdue/ }))
      .toHaveAttribute('href', '/leads?followups=overdue');
    expect(screen.getByRole('link', { name: /3 Due today/ }))
      .toHaveAttribute('href', '/leads?followups=today');
    expect(screen.getByRole('link', { name: /11 Going cold/ }))
      .toHaveAttribute('href', '/leads?stale=1');
    expect(screen.getByRole('link', { name: /24 Unassigned/ }))
      .toHaveAttribute('href', '/leads?assigned_to=unassigned');
  });

  it('hides the unassigned chip when there are none', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload({
      attention: { followups_overdue: 1, followups_today: 0, stale: 0, unassigned: 0 },
    }));

    setup();

    expect(await screen.findByRole('link', { name: /1 Overdue/ })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Unassigned/ })).toBeNull();
  });

  it('shows the agent leaderboard to a manager', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('Agent leaderboard')).toBeInTheDocument();
    expect(screen.getByText('Sara')).toBeInTheDocument();
    expect(screen.getByText('Omar')).toBeInTheDocument();
  });

  it('hides the leaderboard when the backend returns no agents', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload({ agents: [] }));

    setup();

    expect(await screen.findByText('New leads')).toBeInTheDocument();
    expect(screen.queryByText('Agent leaderboard')).toBeNull();
  });

  it('shows the funnel and the decided win rate', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('Pipeline')).toBeInTheDocument();
    // 4 won of 10 decided (4 won + 6 pass) = 40%.
    expect(screen.getByText('40% of decided leads won')).toBeInTheDocument();
  });

  it('shows the credit balance', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('120')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /buy credits/i })).toHaveAttribute('href', '/leads/credits');
  });

  it('surfaces a load failure', async () => {
    vi.spyOn(lib, 'getHuntInsights').mockRejectedValue(new Error('nope'));

    setup();

    expect(await screen.findByText('Could not load your overview.')).toBeInTheDocument();
  });
});
