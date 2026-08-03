import type { Lead, LeadChannel } from '@/types';
import { LEAD_CHANNELS } from '@/types';

/**
 * One place defining what a channel looks like, so the lead timeline and the
 * dashboard chart can never disagree about a channel's name or colour.
 * Colours are the existing leads.css / theme tokens.
 */
export const CHANNEL_META: Record<LeadChannel, { label: string; color: string }> = {
  whatsapp: { label: 'WhatsApp', color: 'var(--mint-500)' },
  instagram: { label: 'Instagram', color: 'var(--violet)' },
  facebook: { label: 'Facebook', color: 'var(--info)' },
  tiktok: { label: 'TikTok', color: 'var(--text-2)' },
  linkedin: { label: 'LinkedIn', color: 'var(--mint-300)' },
  phone: { label: 'Phone', color: 'var(--warn)' },
  email: { label: 'Email', color: 'var(--info)' },
  walk_in: { label: 'Walk-in', color: 'var(--warn)' },
  other: { label: 'Other', color: 'var(--text-4)' },
};

/** Label for a channel key, including the report's synthetic `unattributed`. */
export function channelLabel(key: string): string {
  if (key === 'unattributed') return 'Unattributed';
  return CHANNEL_META[key as LeadChannel]?.label ?? key;
}

export function channelColor(key: string): string {
  if (key === 'unattributed') return 'var(--text-4)';
  return CHANNEL_META[key as LeadChannel]?.color ?? 'var(--text-4)';
}

/**
 * Where tapping this channel should take you, or null when the lead has no
 * handle for it. walk_in and other are never links — they are logged, not opened.
 */
export function channelHref(lead: Lead, channel: LeadChannel): string | null {
  switch (channel) {
    // WhatsApp is only reachable on a mobile number — the backend appends
    // whatsapp_url for any parseable number, including landlines, but
    // is_mobile is the real "WhatsApp is usable here" flag (see
    // Lead::getIsMobileAttribute: "WhatsApp only valid if true").
    case 'whatsapp': return lead.is_mobile ? lead.whatsapp_url ?? null : null;
    case 'phone': return lead.tel_url ?? null;
    case 'email': return lead.email ? `mailto:${lead.email}` : null;
    case 'instagram': return lead.instagram ?? null;
    case 'facebook': return lead.facebook ?? null;
    case 'tiktok': return lead.tiktok ?? null;
    case 'linkedin': return lead.linkedin ?? null;
    default: return null;
  }
}

/** The channels this lead can actually be reached on right now, in list order. */
export function availableChannels(lead: Lead): LeadChannel[] {
  return LEAD_CHANNELS.filter((channel) => channelHref(lead, channel) !== null);
}
