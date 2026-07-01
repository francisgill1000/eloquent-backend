import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as bookingsLib from '@/lib/bookings';
import Bookings from './Bookings';

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Bookings /></ShopProvider></MemoryRouter>);
}

describe('Bookings', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('lists bookings and filters by status', async () => {
    vi.spyOn(bookingsLib, 'getShopBookings').mockResolvedValue({
      data: [
        { id: 1, status: 'booked', customer: { name: 'Alice' }, charges: 50 },
        { id: 2, status: 'completed', customer: { name: 'Bob' }, charges: 90 },
      ],
    });
    setup();
    expect(await screen.findByText('Alice')).toBeInTheDocument();
    expect(screen.getByText('Bob')).toBeInTheDocument();

    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Completed' }));
    expect(screen.queryByText('Alice')).not.toBeInTheDocument();
    expect(screen.getByText('Bob')).toBeInTheDocument();
  });
});
