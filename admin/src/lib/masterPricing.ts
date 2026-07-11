import api from './api';
import type { CreditPack } from '@/types';

// ---- Business Hunt credit packs (master-editable, separate from the Lens
//      subscription price above) --------------------------------------------

export async function getCreditPacks(): Promise<CreditPack[]> {
  const { data } = await api.get('/master/credit-packs');
  return Array.isArray(data?.data) ? data.data : [];
}

export async function createCreditPack(
  p: { name: string; credits: number; price_fils: number; active?: boolean; sort?: number },
): Promise<CreditPack> {
  const { data } = await api.post('/master/credit-packs', p);
  return data?.data ?? data;
}

export async function updateCreditPack(
  id: number,
  p: Partial<{ name: string; credits: number; price_fils: number; active: boolean; sort: number }>,
): Promise<CreditPack> {
  const { data } = await api.patch(`/master/credit-packs/${id}`, p);
  return data?.data ?? data;
}

export async function deleteCreditPack(id: number): Promise<void> {
  await api.delete(`/master/credit-packs/${id}`);
}

export async function getMasterPricing(): Promise<{ monthly: number; annual: number }> {
  const { data } = await api.get('/master/pricing');
  return data;
}

export async function updateMasterPricing(p: { monthly_fils: number; annual_fils: number }): Promise<{ monthly: number; annual: number }> {
  const { data } = await api.patch('/master/pricing', p);
  return data;
}

export async function grantShopDays(shopId: number, grant_days: number): Promise<{ ok: boolean; access_until: string; days_left: number }> {
  const { data } = await api.patch(`/master/shops/${shopId}/subscription`, { grant_days });
  return data;
}
