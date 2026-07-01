import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ShopCard } from './ShopCard';
import type { Shop } from '@/types';

const shop: Shop = {
  id: 7, name: 'Glow Salon', location: 'Marina', shop_code: 'GLOW7',
  is_open: true, is_favourite: false, rating: 4.8,
  today_working_hours: { start_time: '09:00', end_time: '21:00' },
};

describe('ShopCard', () => {
  it('renders name, open badge and code', () => {
    render(<ShopCard shop={shop} onOpen={() => {}} onFavourite={() => {}} />);
    expect(screen.getByText('Glow Salon')).toBeInTheDocument();
    expect(screen.getByText('Open')).toBeInTheDocument();
    expect(screen.getByText('GLOW7')).toBeInTheDocument();
  });

  it('fires onFavourite without triggering onOpen', async () => {
    const onOpen = vi.fn();
    const onFavourite = vi.fn();
    render(<ShopCard shop={shop} onOpen={onOpen} onFavourite={onFavourite} />);
    await userEvent.click(screen.getByLabelText('Toggle favourite'));
    expect(onFavourite).toHaveBeenCalledWith(7);
    expect(onOpen).not.toHaveBeenCalled();
  });
});
