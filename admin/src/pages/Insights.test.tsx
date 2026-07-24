import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as insightsLib from '@/lib/insights';
import type { Insights as InsightsData } from '@/lib/insights';
import Insights from './Insights';

/**
 * A characterization test: it documents what the page renders TODAY so the
 * chart components can be extracted out of it without silently changing the
 * output. If a change here is intentional, update the expectation — but never
 * without looking at the page.
 */
function payload(over: Partial<InsightsData> = {}): InsightsData {
  return {
    range: { from: '2026-07-01', to: '2026-07-03' },
    bookings: { scheduled: 10, booked: 2, completed: 6, cancelled: 1, no_show: 1 },
    rates: { completion: 60, cancellation: 10, no_show: 10 },
    customers: { total: 8, returning: 3, new: 5, repeat_rate: 37.5 },
    reviews: { count: 4, average: 4.5 },
    daily: [
      { date: '2026-07-01', completed: 2, cancelled: 0, no_show: 0, booked: 1, total: 3 },
      { date: '2026-07-02', completed: 3, cancelled: 1, no_show: 0, booked: 0, total: 4 },
      { date: '2026-07-03', completed: 1, cancelled: 0, no_show: 1, booked: 1, total: 3 },
    ],
    ...over,
  };
}

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme', modules: ['bookings'] });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Insights /></ShopProvider></MemoryRouter>);
}

describe('Insights', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('renders the KPI row, charts and review summary', async () => {
    vi.spyOn(insightsLib, 'getInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByText('Total bookings')).toBeInTheDocument();
    // 'Completion' is both a KPI label and a rate-bar label — both are meant.
    expect(screen.getAllByText('Completion').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('No-show rate')).toBeInTheDocument();
    expect(screen.getByText('Avg rating')).toBeInTheDocument();

    // Chart cards.
    expect(screen.getByText('Bookings over time')).toBeInTheDocument();
    expect(screen.getByText('Outcomes')).toBeInTheDocument();
    expect(screen.getByText('Rates')).toBeInTheDocument();
    // 'Customers' is both a card title and the donut caption.
    expect(screen.getAllByText('Customers').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Reviews')).toBeInTheDocument();

    // Donut legend carries the outcome values.
    expect(screen.getByText('Completed')).toBeInTheDocument();
    // The count is wrapped in <b>, so match on the element's full text content.
    expect(screen.getByText((_, el) => el?.textContent === '4 reviews in this range')).toBeInTheDocument();
    // 4.5 shows as both the Avg-rating KPI and the big review number.
    expect(screen.getAllByText('4.5').length).toBeGreaterThanOrEqual(1);
  });

  it('offers the quick range presets', async () => {
    vi.spyOn(insightsLib, 'getInsights').mockResolvedValue(payload());

    setup();

    expect(await screen.findByRole('button', { name: '30 days' })).toHaveAttribute('aria-pressed', 'true');
    ['7 days', '90 days', 'This month', 'Last month', 'This year'].forEach((label) => {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
    });
  });

  it('shows empty states rather than broken charts when there is no data', async () => {
    vi.spyOn(insightsLib, 'getInsights').mockResolvedValue(payload({
      bookings: { scheduled: 0, booked: 0, completed: 0, cancelled: 0, no_show: 0 },
      customers: { total: 0, returning: 0, new: 0, repeat_rate: 0 },
      reviews: { count: 0, average: null },
      daily: [{ date: '2026-07-01', completed: 0, cancelled: 0, no_show: 0, booked: 0, total: 0 }],
    }));

    setup();

    expect(await screen.findByText('No bookings in this range yet.')).toBeInTheDocument();
    expect(screen.getByText('No reviews yet')).toBeInTheDocument();
  });

  it('surfaces a load failure', async () => {
    vi.spyOn(insightsLib, 'getInsights').mockRejectedValue(new Error('nope'));

    setup();

    expect(await screen.findByText('Could not load insights.')).toBeInTheDocument();
  });
});
