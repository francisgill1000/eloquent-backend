import { describe, it, expect } from 'vitest';
import { CHANNEL_META, channelHref, availableChannels } from './channels';
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

describe('channelHref', () => {
  it('uses the server-normalized whatsapp and tel urls', () => {
    const lead = { ...base, whatsapp_url: 'https://wa.me/971501112233', tel_url: 'tel:+971501112233' };
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

  it('has no link for walk_in or other', () => {
    expect(channelHref(base, 'walk_in')).toBeNull();
    expect(channelHref(base, 'other')).toBeNull();
  });
});

describe('availableChannels', () => {
  it('lists only channels the lead can actually be reached on', () => {
    const lead = {
      ...base,
      whatsapp_url: 'https://wa.me/971501112233',
      instagram: 'https://instagram.com/acmegym',
    };
    expect(availableChannels(lead)).toEqual(['whatsapp', 'instagram']);
  });

  it('is empty for a lead with no contact details at all', () => {
    expect(availableChannels(base)).toEqual([]);
  });
});
