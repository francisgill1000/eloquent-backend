import api from './api';

export type Resource = {
  id: number;
  shop_id: number;
  name: string;
  type: string;
  is_active: boolean;
};

export async function getResources(shopId: number): Promise<Resource[]> {
  const { data } = await api.get(`/shops/${shopId}/resources`);
  return Array.isArray(data?.data) ? data.data : [];
}

export async function addResource(shopId: number, payload: { name: string; type?: string }): Promise<Resource> {
  const { data } = await api.post(`/shops/${shopId}/resources`, payload);
  return data?.data ?? data;
}

export async function updateResource(
  shopId: number,
  resourceId: number,
  payload: Partial<Pick<Resource, 'name' | 'type' | 'is_active'>>,
): Promise<Resource> {
  const { data } = await api.put(`/shops/${shopId}/resources/${resourceId}`, payload);
  return data?.data ?? data;
}

export async function deleteResource(shopId: number, resourceId: number): Promise<void> {
  await api.delete(`/shops/${shopId}/resources/${resourceId}`);
}
