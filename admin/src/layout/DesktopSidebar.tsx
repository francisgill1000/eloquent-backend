import { NavLink, useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { WHATSAPP_ENABLED } from '@/lib/features';

/**
 * Persistent desktop navigation rail. Rendered by AppShell for every
 * authenticated screen but only *visible* at ≥1024px (see desktop.css). It
 * replaces the bottom tab bar on large screens and surfaces the screens that
 * live under Settings on mobile (Services / Staff / Working Hours) directly,
 * since desktop has the vertical room. Glass styling matches the app's other
 * frosted surfaces so the ambient background shows through.
 */
type NavItem = { label: string; to: string; icon: keyof typeof Icons; end?: boolean };

const BASE_NAV: NavItem[] = [
  { label: 'Home', to: '/', icon: 'Mic', end: true },
  { label: 'Overview', to: '/overview', icon: 'Chart' },
  { label: 'Bookings', to: '/bookings', icon: 'Calendar' },
  // WhatsApp Chats — hidden while WHATSAPP_ENABLED is off.
  { label: 'Chats', to: '/chats', icon: 'Chat' },
  { label: 'Business Hunt', to: '/leads', icon: 'Search' },
  { label: 'Services', to: '/services', icon: 'Grid' },
  { label: 'Staff', to: '/staff', icon: 'Users' },
  { label: 'Working Hours', to: '/working-hours', icon: 'Clock' },
  { label: 'Settings', to: '/settings', icon: 'Sliders' },
  { label: 'Profile', to: '/profile', icon: 'Store' },
];

export function DesktopSidebar() {
  const { shop, logoutShop } = useShop();
  const navigate = useNavigate();

  const nav = BASE_NAV.filter((n) => WHATSAPP_ENABLED || n.to !== '/chats');
  const items: NavItem[] = shop?.is_master
    ? [...nav, { label: 'All Businesses', to: '/master', icon: 'Key' }]
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
          <div className="ds-status">
            <span className={`c-live-dot${shop?.is_open ? '' : ' off'}`} />
            {shop?.is_open ? 'Open now' : 'Closed'}
          </div>
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
