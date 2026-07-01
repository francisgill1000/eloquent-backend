import api from './api';
import type { Shop } from '@/types';

export type AiCategory = { id: number; name: string; count: number };

export type AiChatMessage = { role: 'user' | 'assistant'; content: string };

export type AiAction =
  | { type: 'navigate'; route: string }
  | { type: 'register'; fields: { name?: string; phone?: string } }
  | { type: 'login'; fields: { phone?: string } };

export type AiSearchResult = {
  reply: string;
  action?: AiAction | null;
  shops: Shop[];
  categories?: AiCategory[];
};

/**
 * Ask the in-app assistant. Sends the conversation so far; the backend runs a
 * tool loop and may return matching shops (ShopCard shape) and/or an action
 * directive (navigate / register / login) for the app to execute. Pass coords
 * when available so "near me" queries rank by distance.
 */
export async function aiSearch(
  messages: AiChatMessage[],
  coords?: { lat: number; lon: number },
): Promise<AiSearchResult> {
  const res = await api.post<AiSearchResult>('/ai/search', {
    messages,
    lat: coords?.lat,
    lon: coords?.lon,
  });
  return res.data;
}

/** Service categories that currently have shops (with counts), for the chips. */
export async function getAiCategories(): Promise<AiCategory[]> {
  const res = await api.get<{ categories: AiCategory[] }>('/ai/categories');
  return res.data.categories ?? [];
}
