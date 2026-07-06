/** The product modules a shop can have enabled. */
export type Module = 'bookings' | 'leads';

/** Minimal shape needed to decide module visibility — satisfied by both the
 *  owner `Shop` and the master-view `MasterShop`. */
type ModuleShop = { is_master?: boolean; modules?: Array<Module> };

/** True if the shop has the module. Master sees everything; a shop with no
 *  modules set falls back to bookings-only (matches the backend default). */
export function shopHasModule(shop: ModuleShop | null, module: Module): boolean {
  if (!shop) return false;
  if (shop.is_master) return true;
  const mods = shop.modules ?? ['bookings'];
  return mods.includes(module);
}

/** True if a nav item (tagged with the modules it belongs to) should show. */
export function navVisible(modules: Module[], shop: ModuleShop | null): boolean {
  return modules.some((m) => shopHasModule(shop, m));
}
