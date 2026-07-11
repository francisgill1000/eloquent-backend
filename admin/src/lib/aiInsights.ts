import api from './api';

export type PeriodType = 'rolling30' | 'week' | 'month' | 'custom';

export type AiInsights = {
  state: 'ok' | 'low_data' | 'error';
  summary: string;
  patterns: string[];
  recommendations: string[];
  message: string;
  generated_at: string;
  cached: boolean;
};

export type AiSummaryHistoryItem = {
  period_from: string;
  period_to: string;
  summary: string;
  patterns: string[];
  recommendations: string[];
  generated_at: string;
};

export async function getAiInsights(
  shopId: number,
  from: string,
  to: string,
  refresh = false,
  period: PeriodType = 'rolling30',
): Promise<AiInsights> {
  const { data } = await api.get('/shop/reports/ai-summary', {
    params: { shop_id: shopId, from, to, period, ...(refresh ? { refresh: 1 } : {}) },
  });
  return data;
}

export async function getAiSummaryHistory(
  shopId: number,
  periodType: Exclude<PeriodType, 'custom'>,
  page = 1,
): Promise<{ data: AiSummaryHistoryItem[]; has_more: boolean }> {
  const { data } = await api.get('/shop/reports/ai-summaries', {
    params: { shop_id: shopId, period_type: periodType, page },
  });
  return data;
}
