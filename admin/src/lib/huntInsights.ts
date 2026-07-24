import api from './api';
import type { LeadStatus } from '@/types';

export type HuntDaily = { date: string; leads: number; won: number; won_value: number };

export type HuntAttention = {
  followups_overdue: number;
  followups_today: number;
  stale: number;
  unassigned: number;
};

export type HuntAgentRow = { id: number; name: string; leads: number; won: number; won_value: number };

export type HuntSummary = {
  range: { from: string; to: string };
  new_leads: number;
  pipeline: Record<LeadStatus, number>;
  total_leads: number;
  moved: Record<LeadStatus, number>;
  won: number;
  won_value: number;
  won_value_recurring: number;
  won_value_one_off: number;
  mrr_won: number;
  credits_used: number;
  searches: number;
};

export type HuntInsights = {
  range: { from: string; to: string };
  summary: HuntSummary;
  daily: HuntDaily[];
  attention: HuntAttention;
  /** Empty for an agent — the backend hides the leaderboard from them. */
  agents: HuntAgentRow[];
  credits: { balance: number; used: number; searches: number };
};

const EMPTY_FUNNEL: Record<LeadStatus, number> =
  { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 };

/**
 * The whole Business Hunt dashboard in one request. The shop comes from the
 * auth token, so there is no shop_id parameter to pass.
 */
export async function getHuntInsights(from: string, to: string): Promise<HuntInsights> {
  const { data } = await api.get('/shop/reports/hunt', { params: { from, to } });
  return {
    range: data?.range ?? { from, to },
    summary: {
      ...data?.summary,
      pipeline: { ...EMPTY_FUNNEL, ...(data?.summary?.pipeline ?? {}) },
      moved: { ...EMPTY_FUNNEL, ...(data?.summary?.moved ?? {}) },
    },
    daily: Array.isArray(data?.daily) ? data.daily : [],
    attention: data?.attention ?? { followups_overdue: 0, followups_today: 0, stale: 0, unassigned: 0 },
    agents: Array.isArray(data?.agents) ? data.agents : [],
    credits: data?.credits ?? { balance: 0, used: 0, searches: 0 },
  };
}
