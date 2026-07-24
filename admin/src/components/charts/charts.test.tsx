import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Donut } from './Donut';
import { RateBars } from './RateBars';
import { TrendChart } from './TrendChart';
import { Kpi, Delta } from './Kpi';
import { EmptyState } from './EmptyState';
import { previousRange, pctChange, daysBetween, presetRange } from '@/lib/dateRange';

describe('Donut', () => {
  it('renders a legend with values and percentages', () => {
    render(<Donut cap="Total" segments={[
      { key: 'a', label: 'Alpha', value: 3, color: 'red' },
      { key: 'b', label: 'Beta', value: 1, color: 'blue' },
    ]} />);
    expect(screen.getByText('Alpha')).toBeInTheDocument();
    expect(screen.getByText('75%')).toBeInTheDocument();
    expect(screen.getByText('25%')).toBeInTheDocument();
  });

  it('falls back to an empty state when every segment is zero', () => {
    render(<Donut cap="Total" emptyText="nothing here" segments={[
      { key: 'a', label: 'Alpha', value: 0, color: 'red' },
    ]} />);
    expect(screen.getByText('nothing here')).toBeInTheDocument();
  });
});

describe('RateBars', () => {
  it('clamps a bar to 100%', () => {
    const { container } = render(<RateBars rows={[{ label: 'Over', value: 240, color: 'red' }]} />);
    expect(screen.getByText('240%')).toBeInTheDocument();
    expect((container.querySelector('.ins-rate-fill') as HTMLElement).style.width).toBe('100%');
  });
});

describe('TrendChart', () => {
  const pts = (vals: number[]) => vals.map((v, i) => ({ date: `2026-07-0${i + 1}`, value: v }));

  it('plots one path per series', () => {
    const { container } = render(<TrendChart series={[
      { key: 'in', label: 'leads', color: 'green', points: pts([1, 2, 3]) },
      { key: 'won', label: 'wins', color: 'gold', points: pts([0, 1, 0]) },
    ]} />);
    // One filled area + two lines.
    expect(container.querySelectorAll('path')).toHaveLength(3);
    expect(screen.getByRole('img', { name: 'leads and wins over time' })).toBeInTheDocument();
  });

  it('shows the empty state when every value is zero', () => {
    render(<TrendChart series={[{ key: 'in', label: 'leads', color: 'green', points: pts([0, 0]) }]} emptyText="no data" />);
    expect(screen.getByText('no data')).toBeInTheDocument();
  });
});

describe('Kpi', () => {
  it('marks a rise as good when up is good, and bad when down is good', () => {
    const { container: up } = render(
      <Kpi label="Won" value="5" delta={<Delta change={20} display="20%" goodDir="up" />} />);
    expect(up.querySelector('.ins-kpi-delta')?.className).toContain('up');

    const { container: down } = render(
      <Kpi label="No-show" value="5" delta={<Delta change={20} display="20%" goodDir="down" />} />);
    expect(down.querySelector('.ins-kpi-delta')?.className).toContain('down');
  });

  it('says so when there is no prior period', () => {
    render(<Kpi label="Won" value="5" delta={<Delta change={null} display="" goodDir="up" />} />);
    expect(screen.getByText('no prior data')).toBeInTheDocument();
  });
});

describe('EmptyState', () => {
  it('shows its text', () => {
    render(<EmptyState text="nothing yet" />);
    expect(screen.getByText('nothing yet')).toBeInTheDocument();
  });
});

describe('dateRange', () => {
  it('computes the immediately preceding window of equal length', () => {
    expect(previousRange('2026-07-08', '2026-07-14')).toEqual({ from: '2026-07-01', to: '2026-07-07' });
  });

  it('counts days inclusively', () => {
    expect(daysBetween('2026-07-01', '2026-07-01')).toBe(1);
    expect(daysBetween('2026-07-01', '2026-07-07')).toBe(7);
  });

  it('returns null percent change when there is no baseline, and 0 when both are zero', () => {
    expect(pctChange(5, 0)).toBeNull();
    expect(pctChange(0, 0)).toBe(0);
    expect(pctChange(150, 100)).toBe(50);
  });

  it('builds a 7-day preset ending today', () => {
    expect(presetRange('7d', new Date(2026, 6, 24))).toEqual({ from: '2026-07-18', to: '2026-07-24' });
  });
});
