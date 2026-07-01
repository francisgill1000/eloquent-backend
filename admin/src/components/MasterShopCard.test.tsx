import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { MasterShop } from '@/types';
import { MasterShopCard } from './MasterShopCard';

const base: MasterShop = {
  id: 7, name: 'Shakaina Salon', shop_code: '339416', pin: '7201',
  phone: '+971500000000', category: 'Salon', status: 'active',
  bookings_count: 12, wa_connected: true, created_at: '2026-06-07T00:00:00Z',
};

describe('MasterShopCard', () => {
  it('shows name, category and connection state, and fires onOpen', async () => {
    const onOpen = vi.fn();
    render(<MasterShopCard shop={base} onOpen={onOpen} />);

    expect(screen.getByText('Shakaina Salon')).toBeInTheDocument();
    expect(screen.getByText(/Salon · \+971500000000/)).toBeInTheDocument();
    expect(screen.getByText(/Connected/)).toBeInTheDocument();
    expect(screen.getByText(/12 bookings/)).toBeInTheDocument();

    await userEvent.setup().click(screen.getByRole('button'));
    expect(onOpen).toHaveBeenCalledWith(7);
  });

  it('marks inactive shops and not-set-up WhatsApp', () => {
    render(<MasterShopCard shop={{ ...base, status: 'inactive', wa_connected: false }} onOpen={() => {}} />);
    expect(screen.getByText(/Inactive/)).toBeInTheDocument();
    expect(screen.getByText(/Not set up/)).toBeInTheDocument();
  });
});
