import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as bookingsLib from '@/lib/bookings';
import * as shopsLib from '@/lib/shops';
import BookingAction from './BookingAction';

function setup() {
  return render(
    <MemoryRouter initialEntries={['/booking/3']}>
      <Routes><Route path="/booking/:id" element={<BookingAction />} /></Routes>
    </MemoryRouter>,
  );
}

describe('BookingAction', () => {
  beforeEach(() => { vi.restoreAllMocks(); vi.stubGlobal('confirm', () => true); });

  it('confirms a booking via status change', async () => {
    vi.spyOn(bookingsLib, 'getBooking').mockResolvedValue({ id: 3, status: 'Booked', customer: { name: 'Sam' }, shop: { id: 7 } });
    vi.spyOn(shopsLib, 'getStaff').mockResolvedValue([]);
    const setStatus = vi.spyOn(bookingsLib, 'setBookingStatus').mockResolvedValue();

    setup();
    expect(await screen.findByText('Sam')).toBeInTheDocument();

    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Completed' }));
    expect(setStatus).toHaveBeenCalledWith(3, 'Completed');
  });
});
