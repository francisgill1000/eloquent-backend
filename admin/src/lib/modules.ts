import type { Shop } from '@/types';

/** The product modules a shop can have enabled. */
export type Module = 'bookings' | 'leads';

/** True if the shop has the module. Master sees everything; a shop with no
 *  modules set falls back to bookings-only (matches the backend default). */
export function shopHasModule(shop: Shop | null, module: Module): boolean {
  if (!shop) return false;
  if (shop.is_master) return true;
  const mods = (shop.modules ?? ['bookings']) as Module[];
  return mods.includes(module);
}

/** True if a nav item (tagged with the modules it belongs to) should show. */
export function navVisible(modules: Module[], shop: Shop | null): boolean {
  return modules.some((m) => shopHasModule(shop, m));
}
