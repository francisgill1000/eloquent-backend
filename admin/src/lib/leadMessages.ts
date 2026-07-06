import api from './api';

export type LeadMessages = {
  opening: string | null;
  followup: string | null;
  default_opening: string;
  default_followup: string;
};

/** The shop's editable WhatsApp outreach templates (opening + follow-up). */
export async function getLeadMessages(): Promise<LeadMessages> {
  const { data } = await api.get('/shop/lead-messages');
  return data;
}

/** Save both templates; pass empty/null to fall back to the packaged default. */
export async function saveLeadMessages(opening: string | null, followup: string | null): Promise<LeadMessages> {
  const { data } = await api.put('/shop/lead-messages', { opening, followup });
  return data;
}

/** AI-generate opening + follow-up templates from the shop profile. Not saved. */
export async function generateLeadMessages(): Promise<{ opening: string; followup: string }> {
  const { data } = await api.post('/shop/lead-messages/generate');
  return { opening: data?.opening ?? '', followup: data?.followup ?? '' };
}
