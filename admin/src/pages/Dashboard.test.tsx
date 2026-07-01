import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as bookingsLib from '@/lib/bookings';
import { formatLocalDate } from '@/lib/date';
import Dashboard from './Dashboard';

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme Salon' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Dashboard /></ShopProvider></MemoryRouter>);
}

describe('Dashboard', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('renders KPIs and an upcoming booking', async () => {
    const today = formatLocalDate(new Date());
    vi.spyOn(bookingsLib, 'getShopBookings').mockResolvedValue({
      data: [{ id: 11, date: today, status: 'booked', charges: 120, customer: { name: 'Sam' }, start_time: '14:00' }],
      total_bookings: 1,
      total_revenue: 5400,
    });
    setup();
    expect(await screen.findByText('AED 5,400')).toBeInTheDocument();
    expect(screen.getByText('Sam')).toBeInTheDocument();
    expect(bookingsLib.getShopBookings).toHaveBeenCalledWith(7);
  });

  it('shows an empty state when there are no upcoming bookings', async () => {
    vi.spyOn(bookingsLib, 'getShopBookings').mockResolvedValue({ data: [], total_bookings: 0, total_revenue: 0 });
    setup();
    expect(await screen.findByText(/no upcoming bookings/i)).toBeInTheDocument();
  });
});
