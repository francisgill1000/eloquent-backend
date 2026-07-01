import api from './api';
import type { Booking, ShopBookingsResponse } from '@/types';

export async function getShopBookings(shopId: number): Promise<ShopBookingsResponse> {
  const { data } = await api.get('/shop/bookings', { params: { shop_id: shopId } });
  return {
    data: Array.isArray(data?.data) ? data.data : [],
    total_bookings: data?.total_bookings,
    total_revenue: data?.total_revenue,
    current_page: data?.current_page,
    last_page: data?.last_page,
  };
}

export async function getBooking(id: number): Promise<Booking> {
  const { data } = await api.get(`/booking/${id}`);
  return data?.data ?? data;
}

export async function setBookingStatus(id: number, status: string): Promise<void> {
  await api.put(`/booking/${id}`, { status });
}

export async function reassignBooking(id: number, staffId: number): Promise<void> {
  await api.post(`/booking/${id}/reassign`, { staff_id: staffId });
}

export async function markReminderSent(id: number): Promise<void> {
  await api.post(`/booking/${id}/mark-reminder-sent`);
}

export async function markInvoicePaid(invoiceId: number): Promise<void> {
  await api.post(`/invoice/${invoiceId}/mark-paid`);
}
