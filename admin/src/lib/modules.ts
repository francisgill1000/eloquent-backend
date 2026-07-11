/** The product modules a shop can have enabled. */
export type Module = 'bookings' | 'leads';

/** Minimal shape needed to decide module visibility — satisfied by both the
 *  owner `Shop` and the master-view `MasterShop`. */
type ModuleShop = { is_master?: boolean; modules?: Array<Module> };

/** True if the shop has the module. Master sees everything; a shop with no
 *  modules set — null, undefined, OR an empty array — falls back to
 *  bookings-only (matches the backend default). The empty-array case matters:
 *  a shop can land with `modules: []` (e.g. its last module toggled off in the
 *  master view), and `?? ['bookings']` does NOT catch `[]`, so without the
 *  length check every nav item is hidden and the whole menu disappears. */
export function shopHasModule(shop: ModuleShop | null, module: Module): boolean {
  if (!shop) return false;
  if (shop.is_master) return true;
  const mods = shop.modules?.length ? shop.modules : ['bookings'];
  return mods.includes(module);
}

/** True if a nav item (tagged with the modules it belongs to) should show. */
export function navVisible(modules: Module[], shop: ModuleShop | null): boolean {
  return modules.some((m) => shopHasModule(shop, m));
}
