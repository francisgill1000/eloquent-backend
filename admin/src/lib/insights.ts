import api from './api';

export type InsightsDaily = {
  date: string;
  completed: number;
  cancelled: number;
  no_show: number;
  booked: number;
  total: number;
};

export type Insights = {
  range: { from: string; to: string };
  bookings: { scheduled: number; booked: number; completed: number; cancelled: number; no_show: number };
  rates: { completion: number; cancellation: number; no_show: number };
  customers: { total: number; returning: number; new: number; repeat_rate: number };
  reviews: { count: number; average: number | null };
  daily: InsightsDaily[];
};

export async function getInsights(shopId: number, from: string, to: string): Promise<Insights> {
  const { data } = await api.get('/shop/reports/insights', { params: { shop_id: shopId, from, to } });
  return data;
}
