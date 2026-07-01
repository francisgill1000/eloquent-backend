import api from './api';
import type { MasterShop, ServiceCategory, Shop, StaffMember, WorkingHours } from '@/types';

export async function shopLogin(shopCode: string, pin: string): Promise<{ token: string; shop: Shop }> {
  const { data } = await api.post('shops/login', { shop_code: shopCode, pin });
  return { token: data.token, shop: data.shop };
}

export async function resetPin(shopCode: string): Promise<unknown> {
  const { data } = await api.post('shops/reset-pin', { shop_code: shopCode });
  return data;
}

export async function registerShop(form: Record<string, unknown>): Promise<{ token?: string; shop?: Shop }> {
  const { data } = await api.post('/shops', form);
  return data;
}

export async function getServiceCategories(): Promise<ServiceCategory[]> {
  const { data } = await api.get('/services');
  return Array.isArray(data) ? data : [];
}

/** One-time category selection for shops registered before the dropdown existed. Locked after. */
export async function confirmShopCategory(categoryId: number): Promise<Shop> {
  const { data } = await api.post('/shop/category', { category_id: categoryId });
  return data?.shop ?? data;
}

export async function reverseGeocode(lat: number, lon: number): Promise<{ location?: string; [k: string]: unknown }> {
  const { data } = await api.get('/location', { params: { lat: lat.toFixed(6), lon: lon.toFixed(6) } });
  return data;
}

export async function updateShop(
  id: number,
  payload: Partial<Shop> | { working_hours: WorkingHours[] },
): Promise<Shop> {
  const { data } = await api.put(`/shops/${id}`, payload);
  // update() responds { message, shop }; older shapes used { data }. Unwrap to the shop.
  return data?.shop ?? data?.data ?? data;
}

/** Full shop incl. the working_hours relation (the login payload omits it). */
export async function getShop(id: number): Promise<Shop> {
  const { data } = await api.get(`/shops/${id}`);
  return data?.data ?? data;
}

export async function getStaff(shopId: number): Promise<StaffMember[]> {
  const { data } = await api.get(`/shops/${shopId}/staff`);
  return Array.isArray(data?.data) ? data.data : Array.isArray(data) ? data : [];
}

export async function addStaff(shopId: number, name: string): Promise<StaffMember> {
  const { data } = await api.post(`/shops/${shopId}/staff`, { name });
  return data?.data ?? data;
}

export async function updateStaff(
  shopId: number,
  staffId: number,
  payload: Partial<StaffMember>,
): Promise<StaffMember> {
  const { data } = await api.put(`/shops/${shopId}/staff/${staffId}`, payload);
  return data?.data ?? data;
}

/** Master account only: every business with its credentials and stats. */
export async function getMasterShops(): Promise<MasterShop[]> {
  const { data } = await api.get('/master/shops');
  return Array.isArray(data?.data) ? data.data : [];
}

/** Master account only: update a business's visibility and/or WhatsApp persona. */
export async function updateMasterShop(
  id: number,
  payload: { status?: 'active' | 'inactive'; persona?: string | null },
): Promise<MasterShop> {
  const { data } = await api.patch(`/master/shops/${id}`, payload);
  return data?.data ?? data;
}

export async function approveQrLogin(token: string): Promise<unknown> {
  const { data } = await api.post(`/shops/qr-login/approve/${token}`);
  return data;
}
