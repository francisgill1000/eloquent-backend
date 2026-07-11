import { describe, it, expect } from 'vitest';
import { shopHasModule, navVisible } from './modules';
import type { Shop } from '@/types';

const shop = (modules: string[], is_master = false) =>
  ({ modules, is_master } as unknown as Shop);

describe('shopHasModule', () => {
  it('is true when the module is present', () => {
    expect(shopHasModule(shop(['bookings', 'leads']), 'leads')).toBe(true);
  });
  it('is false when the module is absent', () => {
    expect(shopHasModule(shop(['bookings']), 'leads')).toBe(false);
  });
  it('defaults a shop with no modules to bookings only', () => {
    expect(shopHasModule({} as Shop, 'bookings')).toBe(true);
    expect(shopHasModule({} as Shop, 'leads')).toBe(false);
  });
  it('treats an empty modules array like unset (bookings only) — not "no menu"', () => {
    expect(shopHasModule(shop([]), 'bookings')).toBe(true);
    expect(shopHasModule(shop([]), 'leads')).toBe(false);
  });
  it('navVisible keeps the menu alive when modules is empty', () => {
    expect(navVisible(['bookings'], shop([]))).toBe(true);
    expect(navVisible(['bookings', 'leads'], shop([]))).toBe(true);
  });
  it('master sees everything', () => {
    expect(shopHasModule(shop([], true), 'leads')).toBe(true);
  });
  it('null shop has nothing', () => {
    expect(shopHasModule(null, 'bookings')).toBe(false);
  });
});

describe('navVisible', () => {
  it('shows an item if any of its modules match', () => {
    expect(navVisible(['bookings', 'leads'], shop(['leads']))).toBe(true);
    expect(navVisible(['bookings'], shop(['leads']))).toBe(false);
  });
});
