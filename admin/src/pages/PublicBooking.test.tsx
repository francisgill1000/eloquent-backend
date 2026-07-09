import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as pub from '@/lib/publicBooking';
import * as bookingsLib from '@/lib/bookings';
import PublicBooking from './PublicBooking';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/book/7']}>
      <Routes><Route path="/book/:shopId" element={<PublicBooking />} /></Routes>
    </MemoryRouter>,
  );
}

describe('PublicBooking', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('loads the shop and books a manual selection', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue({
      id: 7, name: 'FreshPress', catalogs: [{ id: 1, title: 'Classic Haircut', price: 30 }],
    });
    const create = vi.spyOn(bookingsLib, 'createBooking').mockResolvedValue({ id: 55 } as never);

    renderPage();
    const user = userEvent.setup();

    await screen.findByText('Classic Haircut');            // service chip rendered
    const confirm = screen.getByRole('button', { name: /confirm booking/i });
    expect(confirm).toBeDisabled();                        // nothing chosen yet

    await user.click(screen.getByText('Classic Haircut'));
    fireEvent.change(screen.getByLabelText(/date/i), { target: { value: '2026-07-12' } });
    await user.type(screen.getByLabelText(/time/i), '15:00');
    await user.type(screen.getByLabelText(/your name/i), 'Sara');
    await user.type(screen.getByLabelText(/phone/i), '0501234567');

    expect(confirm).toBeEnabled();
    await user.click(confirm);

    await waitFor(() => expect(create).toHaveBeenCalledWith(7, expect.objectContaining({
      customer_name: 'Sara', customer_whatsapp: '0501234567', date: '2026-07-12',
      start_time: '15:00', charges: 30,
      services: [{ title: 'Classic Haircut', price: 30 }],
    })));
    await screen.findByText(/you're booked/i);             // confirmation screen
  });

  it('shows a friendly error when the shop link is invalid', async () => {
    vi.spyOn(pub, 'getPublicShop').mockRejectedValue(new Error('404'));
    renderPage();
    await screen.findByText(/booking link isn't available/i);
  });
});
