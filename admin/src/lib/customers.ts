import api from './api';

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
