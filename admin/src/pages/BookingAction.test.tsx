import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as bookingsLib from '@/lib/bookings';
import * as shopsLib from '@/lib/shops';
import BookingAction from './BookingAction';

// jsdom's PointerEvent drops clientY from the init dict, so attach it directly.
function dragEvent(type: string, clientY: number): Event {
  const ev = new Event(type, { bubbles: true, cancelable: true });
  Object.assign(ev, { pointerId: 1, clientY });
  return ev;
}

function setup() {
  return render(
    <MemoryRouter initialEntries={['/booking/3']}>
      <Routes><Route path="/booking/:id" element={<BookingAction />} /></Routes>
    </MemoryRouter>,
  );
}

describe('BookingAction', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    vi.stubGlobal('confirm', () => true);
    // jsdom doesn't implement Pointer Capture; the knob's drag handlers call these.
    Element.prototype.setPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
    Element.prototype.hasPointerCapture = vi.fn(() => false);
  });

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

  it('commits the snapped status when the knob is dragged and released', async () => {
    vi.spyOn(bookingsLib, 'getBooking').mockResolvedValue({ id: 3, status: 'Booked', customer: { name: 'Sam' }, shop: { id: 7 } });
    vi.spyOn(shopsLib, 'getStaff').mockResolvedValue([]);
    const setStatus = vi.spyOn(bookingsLib, 'setBookingStatus').mockResolvedValue();

    setup();
    await screen.findByText('Sam');

    const knob = screen.getByRole('slider', { name: 'Drag to set status' });
    // jsdom leaves rect.top at 0, so clientY maps straight onto the rail:
    // 36+index*72 → 180px snaps to index 2 (Completed).
    fireEvent(knob, dragEvent('pointerdown', 108));
    fireEvent(knob, dragEvent('pointermove', 180));
    fireEvent(knob, dragEvent('pointerup', 180));

    await waitFor(() => expect(setStatus).toHaveBeenCalledWith(3, 'Completed'));
  });

  it('does not commit when released back on the current status', async () => {
    vi.spyOn(bookingsLib, 'getBooking').mockResolvedValue({ id: 3, status: 'Booked', customer: { name: 'Sam' }, shop: { id: 7 } });
    vi.spyOn(shopsLib, 'getStaff').mockResolvedValue([]);
    const setStatus = vi.spyOn(bookingsLib, 'setBookingStatus').mockResolvedValue();

    setup();
    await screen.findByText('Sam');

    const knob = screen.getByRole('slider', { name: 'Drag to set status' });
    fireEvent(knob, dragEvent('pointerdown', 108)); // Booked = index 1
    fireEvent(knob, dragEvent('pointermove', 120)); // still nearest index 1
    fireEvent(knob, dragEvent('pointerup', 120));

    expect(setStatus).not.toHaveBeenCalled();
  });
});
