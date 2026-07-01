import api from './api';
import type { ParentCategory } from '@/types';

export async function listParentCategories(): Promise<ParentCategory[]> {
  const { data } = await api.get('/shop/parent-categories');
  return Array.isArray(data?.data) ? data.data : Array.isArray(data) ? data : [];
}

export async function createParentCategory(name: string, image?: string | null): Promise<ParentCategory> {
  const { data } = await api.post('/shop/parent-categories', { name, ...(image ? { image } : {}) });
  return data?.data ?? data;
}

export async function updateParentCategory(
  id: number,
  payload: { name?: string; image?: string | null },
): Promise<ParentCategory> {
  const { data } = await api.put(`/shop/parent-categories/${id}`, payload);
  return data?.data ?? data;
}

export async function deleteParentCategory(id: number): Promise<void> {
  await api.delete(`/shop/parent-categories/${id}`);
}
