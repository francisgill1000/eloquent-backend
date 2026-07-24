import { describe, it, expect } from 'vitest';
import { visibleSettingsOptions, visibleSettingsPages } from './nav';
import type { Shop } from '@/types';

const leadsShop = { name: 'S', modules: ['leads'] } as unknown as Shop;
const bookingsShop = { name: 'S', modules: ['bookings'] } as unknown as Shop;
const all = () => true;

describe('nav — Hunt Stats entry', () => {
  it('is offered to a leads shop with leads.view', () => {
    const labels = visibleSettingsOptions(leadsShop, all).map((o) => o.label);
    expect(labels).toContain('Hunt Stats');
  });

  it('is hidden from a bookings-only shop', () => {
    const labels = visibleSettingsOptions(bookingsShop, all).map((o) => o.label);
    expect(labels).not.toContain('Hunt Stats');
  });

  it('is hidden without leads.view', () => {
    const labels = visibleSettingsOptions(leadsShop, (p) => p !== 'leads.view').map((o) => o.label);
    expect(labels).not.toContain('Hunt Stats');
  });

  it('is a shortcut, so it never makes an otherwise-empty Settings menu appear', () => {
    const pages = visibleSettingsPages(leadsShop, all).map((o) => o.label);
    expect(pages).not.toContain('Hunt Stats');
  });
});
