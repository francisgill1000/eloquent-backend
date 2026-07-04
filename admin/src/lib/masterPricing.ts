import api from './api';

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
