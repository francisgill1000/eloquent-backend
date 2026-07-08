import api, { API_BASE } from './api';
import type { Booking, ShopBookingsResponse } from '@/types';

/** Public URL of a booking's invoice PDF (opens inline in the browser). */
export function invoicePdfUrl(bookingId: number): string {
  return `${API_BASE}/booking/${bookingId}/invoice/pdf`;
}

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

export async function updateBookingNotes(id: number, notes: string): Promise<void> {
  await api.patch(`/booking/${id}/notes`, { notes });
}

export type BookSlotPayload = {
  date: string;
  start_time: string;
  services: Array<Record<string, unknown>>;
  charges?: number;
  customer_name?: string;
  customer_whatsapp: string;
};

/** Create a real booking via the same public endpoint the app/customer use.
 *  Returns the created booking (with its id). */
export async function createBooking(shopId: number, payload: BookSlotPayload): Promise<Booking> {
  const { data } = await api.post(`/shops/${shopId}/book`, payload);
  return data?.data ?? data;
}

export type RecurringPayload = {
  date: string;
  start_time: string;
  services: Array<Record<string, unknown>>;
  charges?: number;
  customer_name?: string;
  customer_whatsapp?: string;
  frequency: 'weekly' | 'biweekly' | 'monthly';
  occurrences: number;
};

export async function bookRecurring(
  shopId: number,
  payload: RecurringPayload,
): Promise<{ series_id: string; created: Booking[]; skipped: Array<{ date: string; reason: string }> }> {
  const { data } = await api.post(`/shops/${shopId}/book-recurring`, payload);
  return {
    series_id: data?.series_id,
    created: Array.isArray(data?.created) ? data.created : [],
    skipped: Array.isArray(data?.skipped) ? data.skipped : [],
  };
}
