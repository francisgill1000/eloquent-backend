import api from './api';
import type { Service } from '@/types';

export async function listCatalogs(): Promise<Service[]> {
  const { data } = await api.get('/shop/catalogs');
  return Array.isArray(data?.data) ? data.data : Array.isArray(data) ? data : [];
}

export async function getCatalog(id: number): Promise<Service> {
  const { data } = await api.get(`/shop/catalogs/${id}`);
  return data?.data ?? data;
}

export async function createCatalog(payload: Partial<Service>): Promise<Service> {
  const { data } = await api.post('/shop/catalogs', payload);
  return data?.data ?? data;
}

export async function updateCatalog(id: number, payload: Partial<Service>): Promise<Service> {
  const { data } = await api.put(`/shop/catalogs/${id}`, payload);
  return data?.data ?? data;
}

export async function deleteCatalog(id: number): Promise<void> {
  await api.delete(`/shop/catalogs/${id}`);
}
