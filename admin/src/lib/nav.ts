import { Icons } from '@/components/Icons';
import { WHATSAPP_ENABLED } from '@/lib/features';
import { navVisible, type Module } from '@/lib/modules';

/** A nav item's required permission: a single name, or any-of a list. */
export type Perm = string | string[];

type CanFn = (permission: string) => boolean;
type NavShop = Parameters<typeof navVisible>[1];

const BOTH: Module[] = ['bookings', 'leads'];

/** True if the user satisfies a permission requirement (any-of). No perm = always. */
export function permAllowed(perm: Perm | undefined, can: CanFn): boolean {
  if (!perm) return true;
  return (Array.isArray(perm) ? perm : [perm]).some((p) => can(p));
}

/** Combined product-module + permission gate for a nav item. */
export function navAllowed(item: { modules: Module[]; perm?: Perm }, shop: NavShop, can: CanFn): boolean {
  return navVisible(item.modules, shop) && permAllowed(item.perm, can);
}

/**
 * A "lead agent": a Hunt (leads) user who cannot see every lead (no
 * leads.view_all), so AssignedLeadScope limits them to their own leads. The
 * whole app is scoped to their own pipeline, so shop-wide insight surfaces (the
 * AI summary) are kept out of their reach. Bookings-only shops have no agent
 * concept, so this is false there.
 */
export function isLeadAgent(shop: NavShop, can: CanFn): boolean {
  return navVisible(['leads'], shop) && !can('leads.view_all');
}

/* ------------------------------------------------------------------ */
/* Settings sub-menu — the single source of truth, shared by the      */
/* Settings page and the sidebars (which show "Settings" only when at */
/* least one of these is visible for the user).                       */
/* ------------------------------------------------------------------ */

export type SettingsOption = {
  label: string;
  sub: string;
  to: string;
  icon: keyof typeof Icons;
  modules: Module[];
  /** View permission that reveals this option. Omitted = always shown. */
  perm?: Perm;
  /**
   * True for options that merely duplicate a top-level nav item (Business Hunt,
   * Customers). They live in the Settings list only so mobile — which has no
   * bottom-tab for them — can still reach them. They must NOT count toward
   * whether the (desktop) Settings menu appears: a Manager with only Business
   * Hunt access should see Business Hunt, not a Settings menu holding a
   * duplicate Business Hunt link.
   */
  shortcut?: boolean;
};

const ALL_SETTINGS_OPTIONS: SettingsOption[] = [
  { label: 'Business Hunt', sub: 'Find UAE businesses & win them', to: '/leads', icon: 'Search', modules: ['leads'], perm: 'leads.view', shortcut: true },
  { label: 'Overview', sub: 'Pipeline, wins & what needs chasing', to: '/hunt-insights', icon: 'Chart', modules: ['leads'], perm: 'leads.view', shortcut: true },
  { label: 'Working Hours', sub: 'Set your open & close times', to: '/working-hours', icon: 'Clock', modules: ['bookings'], perm: 'working_hours.view' },
  { label: 'Services', sub: 'Add or edit what you offer', to: '/services', icon: 'Grid', modules: ['bookings'], perm: 'services.view' },
  { label: 'Staff', sub: 'Add & manage your team', to: '/staff', icon: 'Users', modules: ['bookings'], perm: 'staff.view' },
  { label: 'Customers', sub: 'Your customer list & visit history', to: '/customers', icon: 'User', modules: ['bookings'], perm: 'customers.view', shortcut: true },
  { label: 'Demo simulation', sub: 'Play a scripted voice booking to record demo videos', to: '/settings/simulation', icon: 'Mic', modules: ['bookings'], perm: 'settings.manage' },
  { label: 'Recurring booking', sub: 'Set up a regular weekly or monthly appointment', to: '/bookings/recurring', icon: 'Calendar', modules: ['bookings'], perm: 'bookings.view' },
  { label: 'Booking notifications', sub: 'Reminders, reviews & waitlist messages', to: '/settings/notifications', icon: 'Bell', modules: ['bookings'], perm: 'settings.manage' },
  // Reviews is intentionally left ungated (always visible for a bookings shop).
  { label: 'Reviews', sub: 'Customer ratings & feedback', to: '/reviews', icon: 'Heart', modules: ['bookings'] },
  { label: 'Insights', sub: 'No-show, repeat & rating analytics', to: '/insights', icon: 'Chart', modules: ['bookings'], perm: 'reports.view' },
  { label: 'WhatsApp', sub: 'Chat connection settings', to: '/chats/setup', icon: 'WhatsApp', modules: BOTH },
  { label: 'AI Assistant', sub: 'What your auto-reply assistant says', to: '/assistant', icon: 'Chat', modules: BOTH, perm: 'assistant.manage' },
  { label: 'Access Control', sub: 'Users, roles & permissions', to: '/settings/access', icon: 'Key', modules: BOTH, perm: ['users.view', 'roles.view'] },
];

export const SETTINGS_OPTIONS: SettingsOption[] = ALL_SETTINGS_OPTIONS.filter(
  (o) => WHATSAPP_ENABLED || o.to !== '/chats/setup',
);

/** Settings options visible to this shop + user (module + permission gated). */
export function visibleSettingsOptions(shop: NavShop, can: CanFn): SettingsOption[] {
  return SETTINGS_OPTIONS.filter((o) => navAllowed(o, shop, can));
}

/**
 * Visible Settings options that are genuine Settings pages — excludes shortcut
 * entries that duplicate a top-level nav item. This is what decides whether the
 * desktop Settings menu appears, so granting only a top-level feature (e.g.
 * Business Hunt) never surfaces an otherwise-empty Settings menu.
 */
export function visibleSettingsPages(shop: NavShop, can: CanFn): SettingsOption[] {
  return visibleSettingsOptions(shop, can).filter((o) => !o.shortcut);
}

/**
 * The path to send a user to when their landing page (Home / Ask) is hidden by
 * permissions. Walks a priority list and returns the first section they can see.
 */
export function firstAccessiblePath(shop: NavShop, can: CanFn): string {
  const order: Array<{ to: string; modules: Module[]; perm?: Perm; settings?: boolean }> = [
    { to: '/', modules: BOTH, perm: 'assistant.use' },
    { to: '/bookings', modules: ['bookings'], perm: 'bookings.view' },
    { to: '/leads', modules: ['leads'], perm: 'leads.view' },
    { to: '/ai-summary', modules: BOTH, perm: 'summary.view' },
    { to: '/customers', modules: ['bookings'], perm: 'customers.view' },
    { to: '/settings', modules: BOTH, settings: true },
    { to: '/profile', modules: BOTH, perm: 'profile.view' },
  ];
  for (const c of order) {
    if (c.settings) {
      if (visibleSettingsOptions(shop, can).length > 0) return c.to;
      continue;
    }
    // The shop-wide AI summary is not a landing place for a lead agent.
    if (c.to === '/ai-summary' && isLeadAgent(shop, can)) continue;
    if (navAllowed(c, shop, can)) return c.to;
  }
  return '/profile';
}
