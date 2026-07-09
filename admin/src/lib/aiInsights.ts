import api from './api';

export type AiInsights = {
  state: 'ok' | 'low_data' | 'error';
  summary: string;
  patterns: string[];
  recommendations: string[];
  message: string;
  generated_at: string;
  cached: boolean;
};

export async function getAiInsights(
  shopId: number,
  from: string,
  to: string,
  refresh = false,
): Promise<AiInsights> {
  const { data } = await api.get('/shop/reports/ai-summary', {
    params: { shop_id: shopId, from, to, ...(refresh ? { refresh: 1 } : {}) },
  });
  return data;
}
