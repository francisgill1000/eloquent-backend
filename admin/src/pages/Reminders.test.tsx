import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as bookingsLib from '@/lib/bookings';
import Reminders from './Reminders';

function tomorrow(): string {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Reminders /></ShopProvider></MemoryRouter>);
}

describe('Reminders', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
    vi.stubGlobal('open', vi.fn());
  });

  it('marks a reminder as sent', async () => {
    vi.spyOn(bookingsLib, 'getShopBookings').mockResolvedValue({
      data: [{ id: 5, date: tomorrow(), status: 'booked', customer_name: 'Dana', customer_whatsapp: '971500000000', start_time: '10:00' }],
    });
    const mark = vi.spyOn(bookingsLib, 'markReminderSent').mockResolvedValue();

    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /send/i }));
    expect(mark).toHaveBeenCalledWith(5);
    expect(await screen.findByText(/sent/i)).toBeInTheDocument();
  });
});
