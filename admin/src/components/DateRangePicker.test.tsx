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
});
