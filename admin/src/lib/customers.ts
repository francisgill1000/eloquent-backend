import api from './api';

export type CustomerDetail = {
  id: number;
  name: string | null;
  whatsapp: string | null;
  notes: string | null;
  preferences: Record<string, unknown> | null;
  bookings_count: number;
  last_visit_date: string | null;
  total_spent: number;
};

export async function getCustomer(shopId: number, customerId: number): Promise<CustomerDetail> {
  const { data } = await api.get(`/shops/${shopId}/customers/${customerId}`);
  return data?.data ?? data;
}

export async function updateCustomer(
  shopId: number,
  customerId: number,
  payload: { name?: string; notes?: string | null; preferences?: Record<string, unknown> | null },
): Promise<CustomerDetail> {
  const { data } = await api.patch(`/shops/${shopId}/customers/${customerId}`, payload);
  return data?.data ?? data;
}

/**
 * Total number of customers for a shop. The customers endpoint returns a
 * Laravel paginator, whose `total` is the full count — so we ask for a single
 * row and read the total off the envelope.
 */
export async function getCustomerCount(shopId: number): Promise<number> {
  const { data } = await api.get(`/shops/${shopId}/customers`, { params: { per_page: 1 } });
  if (typeof data?.total === 'number') return data.total;
  return Array.isArray(data?.data) ? data.data.length : 0;
}
