import type { Service } from '@/types';

export const DEFAULT_SECTION = 'Services';

export type ServiceGroup = { name: string; image?: string | null; items: Service[] };

/**
 * Group services by parent category. Categorised sections come first (in
 * first-seen order); uncategorised services land last under "Services".
 * Shared by the shop detail page and the in-chat services list so the two
 * never drift.
 */
export function groupByParentCategory(items: Service[]): ServiceGroup[] {
  const groups = new Map<string, ServiceGroup>();
  for (const item of items) {
    const name = item.parent_category?.name ?? DEFAULT_SECTION;
    if (!groups.has(name)) groups.set(name, { name, image: item.parent_category?.image ?? null, items: [] });
    groups.get(name)!.items.push(item);
  }
  return [...groups.values()]
    .sort((a, b) => (a.name === DEFAULT_SECTION ? 1 : b.name === DEFAULT_SECTION ? -1 : 0));
}
