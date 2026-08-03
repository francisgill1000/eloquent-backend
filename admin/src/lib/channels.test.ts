import { describe, it, expect } from 'vitest';
import { CHANNEL_META, channelColor, channelHref, channelLabel, availableChannels } from './channels';
import { LEAD_CHANNELS } from '@/types';
import type { Lead } from '@/types';

const base: Lead = { id: 1, name: 'Acme', status: 'sent' };

describe('CHANNEL_META', () => {
  it('has a label and colour for every channel', () => {
    for (const channel of LEAD_CHANNELS) {
      expect(CHANNEL_META[channel]?.label, channel).toBeTruthy();
      expect(CHANNEL_META[channel]?.color, channel).toBeTruthy();
    }
  });
});

describe('channelLabel', () => {
  it('returns the CHANNEL_META label for a known channel', () => {
    expect(channelLabel('whatsapp')).toBe(CHANNEL_META.whatsapp.label);
    expect(channelLabel('instagram')).toBe(CHANNEL_META.instagram.label);
  });

  it('handles the synthetic unattributed key, which is not in LEAD_CHANNELS', () => {
    expect(LEAD_CHANNELS).not.toContain('unattributed');
    expect(channelLabel('unattributed')).toBe('Unattributed');
  });

  it('falls back to the raw key for an unknown channel', () => {
    expect(channelLabel('carrier_pigeon')).toBe('carrier_pigeon');
  });
});

describe('channelColor', () => {
  it('returns the CHANNEL_META colour for a known channel', () => {
    expect(channelColor('whatsapp')).toBe(CHANNEL_META.whatsapp.color);
    expect(channelColor('instagram')).toBe(CHANNEL_META.instagram.color);
  });

  it('returns a muted colour for the synthetic unattributed key', () => {
    expect(LEAD_CHANNELS).not.toContain('unattributed');
    expect(channelColor('unattributed')).toBe('var(--text-4)');
  });

  it('falls back to a muted colour for an unknown channel rather than undefined', () => {
    expect(channelColor('carrier_pigeon')).toBe('var(--text-4)');
  });
});

describe('channelHref', () => {
  it('uses the server-normalized whatsapp and tel urls', () => {
    const lead = { ...base, is_mobile: true, whatsapp_url: 'https://wa.me/971501112233', tel_url: 'tel:+971501112233' };
    expect(channelHref(lead, 'whatsapp')).toBe('https://wa.me/971501112233');
    expect(channelHref(lead, 'phone')).toBe('tel:+971501112233');
  });

  it('builds a mailto for email', () => {
    expect(channelHref({ ...base, email: 'owner@acmegym.ae' }, 'email')).toBe('mailto:owner@acmegym.ae');
  });

  it('returns the stored handle url for social channels', () => {
    const lead = { ...base, instagram: 'https://instagram.com/acmegym' };
    expect(channelHref(lead, 'instagram')).toBe('https://instagram.com/acmegym');
  });

  it('returns null when the lead has no handle for that channel', () => {
    expect(channelHref(base, 'instagram')).toBeNull();
    expect(channelHref(base, 'whatsapp')).toBeNull();
  });

  it('returns null for whatsapp on a landline — is_mobile false is the real gate, not just whatsapp_url being present', () => {
    // The backend appends whatsapp_url for any parseable number, including
    // landlines; is_mobile is the actual "WhatsApp is usable here" flag
    // (see Lead::getIsMobileAttribute docblock: "WhatsApp only valid if true").
    const lead = { ...base, is_mobile: false, whatsapp_url: 'https://wa.me/97143334444' };
    expect(channelHref(lead, 'whatsapp')).toBeNull();
    expect(availableChannels(lead)).not.toContain('whatsapp');
  });

  it('has no link for walk_in or other', () => {
    expect(channelHref(base, 'walk_in')).toBeNull();
    expect(channelHref(base, 'other')).toBeNull();
  });
});

describe('availableChannels', () => {
  it('lists only channels the lead can actually be reached on', () => {
    const lead = {
      ...base,
      is_mobile: true,
      whatsapp_url: 'https://wa.me/971501112233',
      instagram: 'https://instagram.com/acmegym',
    };
    expect(availableChannels(lead)).toEqual(['whatsapp', 'instagram']);
  });

  it('is empty for a lead with no contact details at all', () => {
    expect(availableChannels(base)).toEqual([]);
  });
});
