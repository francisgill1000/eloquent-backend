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

export type CustomerListItem = {
  id: number;
  name: string | null;
  whatsapp: string | null;
  whatsapp_normalized: string | null;
  bookings_count: number;
  total_spent: number | string;
  last_visit_date: string | null;
  first_visit_date: string | null;
};

export type Paginated<T> = {
  data: T[];
  total: number;
  current_page: number;
  last_page: number;
  per_page: number;
};

/** Paginated, searchable list of a shop's customers (one row per contact number). */
export async function listCustomers(
  shopId: number,
  params: { search?: string; page?: number; per_page?: number } = {},
): Promise<Paginated<CustomerListItem>> {
  const { data } = await api.get(`/shops/${shopId}/customers`, { params });
  return {
    data: Array.isArray(data?.data) ? data.data : [],
    total: data?.total ?? 0,
    current_page: data?.current_page ?? 1,
    last_page: data?.last_page ?? 1,
    per_page: data?.per_page ?? 20,
  };
}
