import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { DateRangePicker } from './DateRangePicker';

describe('DateRangePicker', () => {
  const far = new Date(2030, 0, 1);

  it('sets the end date when a start already exists', () => {
    const onChange = vi.fn();
    render(<DateRangePicker from="2026-03-01" to="" onChange={onChange} max={far} />);
    fireEvent.click(screen.getByLabelText('2026-03-15'));
    expect(onChange).toHaveBeenCalledWith('2026-03-01', '2026-03-15');
  });

  it('swaps when the end is before the start', () => {
    const onChange = vi.fn();
    render(<DateRangePicker from="2026-03-10" to="" onChange={onChange} max={far} />);
    fireEvent.click(screen.getByLabelText('2026-03-05'));
    expect(onChange).toHaveBeenCalledWith('2026-03-05', '2026-03-10');
  });

  it('starts a fresh range when a full range is already selected', () => {
    const onChange = vi.fn();
    render(<DateRangePicker from="2026-03-01" to="2026-03-10" onChange={onChange} max={far} />);
    fireEvent.click(screen.getByLabelText('2026-03-20'));
    expect(onChange).toHaveBeenCalledWith('2026-03-20', '');
  });

  it('previews the in-between range on hover before the end is picked', () => {
    render(<DateRangePicker from="2026-03-10" to="" onChange={vi.fn()} max={far} />);
    fireEvent.mouseEnter(screen.getByLabelText('2026-03-15'));
    // A day between the start and the hovered day is highlighted as in-range…
    expect(screen.getByLabelText('2026-03-12').className).toContain('drp-in');
    // …and the hovered day reads as the (tentative) endpoint.
    expect(screen.getByLabelText('2026-03-15').className).toContain('drp-end');
  });

  it('renders two month panes by default', () => {
    render(<DateRangePicker from="2026-03-01" to="" onChange={vi.fn()} max={far} />);
    // Default 2 panes → the from-month and the next month are both titled.
    expect(screen.getByText('March 2026')).toBeTruthy();
    expect(screen.getByText('April 2026')).toBeTruthy();
  });
});
