import { NavLink, useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { WHATSAPP_ENABLED } from '@/lib/features';
import { type Module } from '@/lib/modules';
import { navAllowed, visibleSettingsPages, type Perm } from '@/lib/nav';

/**
 * Persistent desktop navigation rail. Rendered by AppShell for every
 * authenticated screen but only *visible* at ≥1024px (see desktop.css). It
 * replaces the bottom tab bar on large screens. Services / Staff / Working
 * Hours are reached through Settings (as on mobile), so they aren't top-level
 * rail items. Glass styling matches the app's other frosted surfaces so the
 * ambient background shows through.
 */
type NavItem = { label: string; to: string; icon: keyof typeof Icons; end?: boolean; modules: Module[]; perm?: Perm };

const BOTH: Module[] = ['bookings', 'leads'];

const BASE_NAV: NavItem[] = [
  { label: 'AI Summary', to: '/ai-summary', icon: 'Sparkle', modules: BOTH, perm: 'summary.view' },
  { label: 'Home', to: '/', icon: 'Home', end: true, modules: BOTH, perm: 'assistant.use' },
  // Your past conversations with the Ask assistant.
  { label: 'Chats', to: '/conversations', icon: 'Chat', modules: BOTH, perm: 'chats.view' },
  { label: 'Bookings', to: '/bookings', icon: 'Calendar', modules: ['bookings'], perm: 'bookings.view' },
  { label: 'Customers', to: '/customers', icon: 'Users', modules: ['bookings'], perm: 'customers.view' },
  // WhatsApp Chats — hidden while WHATSAPP_ENABLED is off.
  { label: 'Chats', to: '/chats', icon: 'Chat', modules: BOTH },
  { label: 'Business Hunt', to: '/leads', icon: 'Search', modules: ['leads'], perm: 'leads.view' },
  { label: 'Hunt Stats', to: '/hunt-insights', icon: 'Chart', modules: ['leads'], perm: 'leads.view' },
  // Services / Staff / Working Hours are reached via Settings (like on mobile),
  // so they're intentionally not surfaced as top-level sidebar items.
  { label: 'Settings', to: '/settings', icon: 'Sliders', modules: BOTH },
  { label: 'Profile', to: '/profile', icon: 'Store', modules: BOTH, perm: 'profile.view' },
];

export function DesktopSidebar() {
  const { shop, can, logoutShop } = useShop();
  const navigate = useNavigate();

  const nav = BASE_NAV
    .filter((n) => WHATSAPP_ENABLED || n.to !== '/chats')
    // Settings is a container: show it only if the user can see ≥1 real Settings
    // page (shortcuts like Business Hunt are already top-level items here, so they
    // don't count — otherwise granting only Business Hunt would surface Settings).
    .filter((n) => (n.to === '/settings' ? visibleSettingsPages(shop, can).length > 0 : navAllowed(n, shop, can)));
  // A master is the operator account — it only manages other businesses, so it
  // gets a single menu item instead of the shop-operational nav.
  const items: NavItem[] = shop?.is_master
    ? [{ label: 'All Businesses', to: '/master', icon: 'Key', modules: BOTH }]
    : nav;

  const logout = () => { logoutShop(); navigate('/login'); };

  return (
    <aside className="ds-rail" aria-label="Main navigation">
      <div className="ds-head">
        <div className="ds-orb">
          {shop?.logo
            ? <img src={shop.logo} alt="" />
            : (Array.from(shop?.name || '?')[0] || '?').toUpperCase()}
        </div>
        <div className="ds-head-text">
          <div className="ds-name">{shop?.name ?? 'Business Lens'}</div>
        </div>
      </div>

      <nav className="ds-nav">
        {items.map((item) => {
          const Icon = Icons[item.icon];
          return (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) => `ds-link${isActive ? ' active' : ''}`}
            >
              <span className="ds-link-ic"><Icon size={19} /></span>
              <span>{item.label}</span>
            </NavLink>
          );
        })}
      </nav>

      <button className="ds-logout" onClick={logout}>
        <span className="ds-link-ic"><Icons.Logout size={19} /></span>
        <span>Log out</span>
      </button>
    </aside>
  );
}
