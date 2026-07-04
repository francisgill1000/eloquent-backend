import api from './api';

export type SubStatus = {
  status: 'trialing' | 'active' | 'expired';
  plan: 'monthly' | 'annual' | null;
  access_until: string | null;
  trial_ends_at: string | null;
  days_left: number;
  prices: { monthly: number; annual: number };
};

export async function getSubscription(): Promise<SubStatus> {
  const { data } = await api.get('/shop/subscription');
  return data;
}

export async function startCheckout(plan: 'monthly' | 'annual'): Promise<{ redirect_url: string; intent_id: string }> {
  const { data } = await api.post('/shop/subscription/checkout', { plan });
  return data;
}
