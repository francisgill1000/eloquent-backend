import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { getShopBookings, setBookingStatus, createBooking } from './bookings';

describe('bookings wrappers', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('normalizes the shop bookings response', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [{ id: 1 }], total_bookings: 1, total_revenue: 50 } });
    const res = await getShopBookings(7);
    expect(api.get).toHaveBeenCalledWith('/shop/bookings', { params: { shop_id: 7 } });
    expect(res.data).toHaveLength(1);
    expect(res.total_revenue).toBe(50);
  });

  it('returns empty data array when response is malformed', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: {} });
    const res = await getShopBookings(7);
    expect(res.data).toEqual([]);
  });

  it('PUTs status changes', async () => {
    const put = vi.spyOn(api, 'put').mockResolvedValue({ data: {} });
    await setBookingStatus(3, 'confirmed');
    expect(put).toHaveBeenCalledWith('/booking/3', { status: 'confirmed' });
  });

  it('creates a booking and unwraps the returned record', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: { id: 42, status: 'Booked' } } });
    const payload = { date: '2026-07-09', start_time: '09:30', services: [{ title: 'Hair Cut', price: '150.00' }], charges: 150, customer_name: 'Sarah', customer_whatsapp: '055 010 2030' };
    const b = await createBooking(7, payload);
    expect(post).toHaveBeenCalledWith('/shops/7/book', payload);
    expect(b.id).toBe(42);
  });
});
