import api from './api';

export type Review = {
  id: number;
  rating: number;
  comment: string | null;
  customer_name: string | null;
  date: string | null;
  rated_at: string | null;
};

export type ReviewSummary = {
  count: number;
  average: number | null;
  distribution: Record<string, number>;
};

export async function getReviews(shopId: number): Promise<{ data: Review[]; summary: ReviewSummary }> {
  const { data } = await api.get('/shop/reviews', { params: { shop_id: shopId } });
  return {
    data: Array.isArray(data?.data) ? data.data : [],
    summary: data?.summary ?? { count: 0, average: null, distribution: {} },
  };
}
