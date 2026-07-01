import api from './api';

export async function toggleFavourite(shopId: number): Promise<void> {
  await api.post(`/shops/${shopId}/favourite`);
}
