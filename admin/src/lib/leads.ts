import api from './api';
import type {
  CreditPack,
  Lead,
  LeadDetailResponse,
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

/** Raised when the shop is out of Business Hunt credits (HTTP 429). Carries the
 *  current balance so the UI can show a top-up prompt. */
export class InsufficientCreditsError extends Error {
  constructor(public credits: number) {
    super('Out of Business Hunt credits.');
    this.name = 'InsufficientCreditsError';
  }
}

/** The shop's Business Hunt credit balance, whether it may self-serve buy, and
 *  the packs it can top up with. */
export async function getLeadCredits(): Promise<{ credits: number; can_purchase: boolean; embedded_checkout: boolean; packs: CreditPack[] }> {
  const { data } = await api.get('/shop/leads/credits');
  return {
    credits: Number(data?.credits ?? 0),
    can_purchase: Boolean(data?.can_purchase),
    embedded_checkout: Boolean(data?.embedded_checkout),
    packs: Array.isArray(data?.packs) ? data.packs : [],
  };
}

/** Start a Ziina checkout for a pack. Returns both the hosted-page redirect_url
 *  and the embedded_url (inline iframe); the client uses one based on the
 *  embedded_checkout flag. The webhook grants the credits once payment completes.
 *  403 if the shop isn't allowed to buy. */
export async function startPackCheckout(packId: number): Promise<{ redirect_url: string | null; embedded_url: string | null; intent_id: string | null }> {
  const { data } = await api.post('/shop/leads/purchase', { pack_id: packId });
  return {
    redirect_url: data?.redirect_url ?? null,
    embedded_url: data?.embedded_url ?? null,
    intent_id: data?.intent_id ?? null,
  };
}

/**
 * Search real businesses via the active source. Does not save. Cache hits are
 * free; a live call spends one credit, or throws InsufficientCreditsError.
 */
export async function searchLeads(category: string, area?: string): Promise<LeadSearchResponse> {
  try {
    const { data } = await api.get('/shop/leads/search', {
      params: { category, area: area || undefined },
    });
    return {
      data: Array.isArray(data?.data) ? data.data : [],
      meta: data?.meta ?? { from_cache: false, credits: 0 },
    };
  } catch (err) {
    const res = (err as { response?: { status?: number; data?: { credits?: number } } })?.response;
    if (res?.status === 429) {
      throw new InsufficientCreditsError(res.data?.credits ?? 0);
    }
    throw err;
  }
}

export type AdSearchStart =
  | { cached: true; data: LeadResult[]; cachedAt?: string }
  | { cached: false; runId: string };

/**
 * Start an "Ad Activity" search. A repeat query hits the cache and returns
 * results immediately ({cached:true}); otherwise it kicks off an async scrape
 * and returns a run id to poll. Pass fresh=true to bypass the cache and force a
 * live re-scrape. Throws InsufficientCreditsError on 429.
 */
export async function startAdSearch(category: string, area?: string, fresh = false): Promise<AdSearchStart> {
  try {
    const { data } = await api.post('/shop/leads/ad-search', { category, area: area || undefined, fresh: fresh || undefined });
    if (data?.cached) {
      return { cached: true, data: Array.isArray(data.data) ? data.data : [], cachedAt: data.cached_at };
    }
    return { cached: false, runId: data?.run_id as string };
  } catch (err) {
    const res = (err as { response?: { status?: number; data?: { credits?: number } } })?.response;
    if (res?.status === 429) {
      throw new InsufficientCreditsError(res.data?.credits ?? 0);
    }
    throw err;
  }
}

export type AdSearchPoll = { status: 'running' | 'done' | 'failed'; data: LeadResult[] };

/** Poll an Ad Activity scrape run. `category` is echoed back so the server can cache the result. */
export async function pollAdSearch(runId: string, category: string): Promise<AdSearchPoll> {
  const { data } = await api.get(`/shop/leads/ad-search/${runId}`, { params: { category } });
  return {
    status: data?.status ?? 'running',
    data: Array.isArray(data?.data) ? data.data : [],
  };
}

/**
 * Persist selected search results as leads (deduped on external_ref server-side).
 * Returns the saved rows plus `created` — how many were actually new (re-saving
 * an existing lead dedupes and doesn't count).
 */
export async function saveLeads(leads: LeadResult[], pipeline: string): Promise<{ leads: Lead[]; created: number }> {
  const { data } = await api.post('/shop/leads', { leads, pipeline });
  return {
    leads: Array.isArray(data?.data) ? data.data : [],
    created: typeof data?.created === 'number' ? data.created : 0,
  };
}

/** A single saved lead with its activity history, for the detail page. */
export async function getLead(id: number): Promise<LeadDetailResponse> {
  const { data } = await api.get(`/shop/leads/${id}`);
  return {
    lead: data?.data ?? data,
    activities: Array.isArray(data?.activities) ? data.activities : [],
  };
}

/** Move a lead through the funnel; writes an activity + bumps last_contacted_at. */
export async function updateLeadStatus(id: number, status: LeadStatus, note?: string): Promise<Lead> {
  const { data } = await api.patch(`/shop/leads/${id}/status`, { status, note });
  return data?.data ?? data;
}

/** Record a follow-up nudge; logs a `contacted` activity + bumps last_contacted_at. */
export async function logFollowup(id: number): Promise<Lead> {
  const { data } = await api.post(`/shop/leads/${id}/followup`);
  return data?.data ?? data;
}

/** AI-write one ready-to-send message for this specific lead. Not saved. */
export async function personalizeLead(id: number, kind: 'opening' | 'followup'): Promise<string> {
  const { data } = await api.post(`/shop/leads/${id}/personalize`, { kind });
  return data?.message ?? '';
}

export type LeadFilters = {
  status?: LeadStatus;
  category?: string;
  pipeline?: string;
  search?: string;
  followups?: 'due';
};

/** List the shop's leads with filters + funnel counts per status + pipelines. */
export async function listLeads(filters: LeadFilters = {}): Promise<LeadListResponse> {
  const { data } = await api.get('/shop/leads', { params: filters });
  return {
    data: Array.isArray(data?.data) ? data.data : [],
    funnel: data?.funnel ?? { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 },
    pipelines: Array.isArray(data?.pipelines) ? data.pipelines : [],
  };
}
