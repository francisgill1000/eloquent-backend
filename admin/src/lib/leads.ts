import api from './api';
import type {
  Lead,
  LeadListResponse,
  LeadResult,
  LeadSearchResponse,
  LeadStatus,
} from '@/types';

/**
 * Normalize a phone to UAE international digits (mirrors the backend Lead
 * accessor). Used for acting on unsaved search results; saved leads already
 * carry server-computed whatsapp_url / tel_url / is_mobile.
 */
export function leadDigits(phone?: string | null): string | null {
  if (!phone) return null;
  let d = phone.replace(/\D+/g, '');
  if (!d) return null;
  if (d.startsWith('00')) d = d.slice(2);
  if (d.startsWith('971')) return d;
  if (d.startsWith('0')) return '971' + d.replace(/^0+/, '');
  if (d.startsWith('5') && d.length === 9) return '971' + d;
  return d;
}

export function isUaeMobile(phone?: string | null): boolean {
  const d = leadDigits(phone);
  return !!d && d.startsWith('9715') && d.length === 12;
}

export function waLink(phone?: string | null): string | null {
  const d = leadDigits(phone);
  return d ? `https://wa.me/${d}` : null;
}

export function telLink(phone?: string | null): string | null {
  const d = leadDigits(phone);
  return d ? `tel:+${d}` : null;
}

/** Raised when the monthly search allowance is exhausted (HTTP 429). */
export class SearchLimitError extends Error {
  constructor(public used: number, public limit: number) {
    super('Monthly search allowance reached.');
    this.name = 'SearchLimitError';
  }
}

/**
 * Search real businesses via the active source. Does not save. Cache hits are
 * free; a live call over the monthly allowance throws SearchLimitError.
 */
export async function searchLeads(category: string, area?: string): Promise<LeadSearchResponse> {
  try {
    const { data } = await api.get('/shop/leads/search', {
      params: { category, area: area || undefined },
    });
    return {
      data: Array.isArray(data?.data) ? data.data : [],
      meta: data?.meta ?? { from_cache: false, used: 0, limit: 0, remaining: 0 },
    };
  } catch (err) {
    const res = (err as { response?: { status?: number; data?: { used?: number; limit?: number } } })?.response;
    if (res?.status === 429) {
      throw new SearchLimitError(res.data?.used ?? 0, res.data?.limit ?? 0);
    }
    throw err;
  }
}

/**
 * Start an async "Ad Activity" scrape (Meta Ad Library). Returns a run id to
 * poll. Throws SearchLimitError on 429.
 */
export async function startAdSearch(category: string, area?: string): Promise<string> {
  try {
    const { data } = await api.post('/shop/leads/ad-search', { category, area: area || undefined });
    return data?.run_id as string;
  } catch (err) {
    const res = (err as { response?: { status?: number; data?: { used?: number; limit?: number } } })?.response;
    if (res?.status === 429) {
      throw new SearchLimitError(res.data?.used ?? 0, res.data?.limit ?? 0);
    }
    throw err;
  }
}

export type AdSearchPoll = { status: 'running' | 'done' | 'failed'; data: LeadResult[] };

/** Poll an Ad Activity scrape run. */
export async function pollAdSearch(runId: string): Promise<AdSearchPoll> {
  const { data } = await api.get(`/shop/leads/ad-search/${runId}`);
  return {
    status: data?.status ?? 'running',
    data: Array.isArray(data?.data) ? data.data : [],
  };
}

/** Persist selected search results as leads (deduped on external_ref server-side). */
export async function saveLeads(leads: LeadResult[]): Promise<Lead[]> {
  const { data } = await api.post('/shop/leads', { leads });
  return Array.isArray(data?.data) ? data.data : [];
}

/** Move a lead through the funnel; writes an activity + bumps last_contacted_at. */
export async function updateLeadStatus(id: number, status: LeadStatus, note?: string): Promise<Lead> {
  const { data } = await api.patch(`/shop/leads/${id}/status`, { status, note });
  return data?.data ?? data;
}

export type LeadFilters = {
  status?: LeadStatus;
  category?: string;
  search?: string;
  followups?: 'due';
};

/** List the shop's leads with filters + funnel counts per status. */
export async function listLeads(filters: LeadFilters = {}): Promise<LeadListResponse> {
  const { data } = await api.get('/shop/leads', { params: filters });
  return {
    data: Array.isArray(data?.data) ? data.data : [],
    funnel: data?.funnel ?? { new: 0, sent: 0, replied: 0, demo: 0, won: 0, pass: 0 },
  };
}
