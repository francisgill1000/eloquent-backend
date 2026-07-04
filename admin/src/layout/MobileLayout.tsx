import { Link, Outlet, useLocation } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { WHATSAPP_ENABLED } from '@/lib/features';

type Tab = { id: string; label: string; href: string; icon: keyof typeof Icons };

const ALL_TABS: Tab[] = [
  { id: 'home', label: 'Home', href: '/', icon: 'Mic' },
  { id: 'overview', label: 'Overview', href: '/overview', icon: 'Chart' },
  { id: 'bookings', label: 'Bookings', href: '/bookings', icon: 'Calendar' },
  // WhatsApp Chats — hidden temporarily behind WHATSAPP_ENABLED.
  { id: 'chats', label: 'Chats', href: '/chats', icon: 'Chat' },
  // Reminders tab hidden for now — page still reachable at /reminders.
  { id: 'settings', label: 'Settings', href: '/settings', icon: 'Sliders' },
  { id: 'profile', label: 'Profile', href: '/profile', icon: 'Store' },
];
const tabs = ALL_TABS.filter((t) => WHATSAPP_ENABLED || t.id !== 'chats');

function activeTab(path: string): string {
  if (path === '/') return 'home';
  if (path.startsWith('/overview')) return 'overview';
  if (path.startsWith('/bookings') || path.startsWith('/booking')) return 'bookings';
  if (path.startsWith('/chats')) return 'chats';
  if (path.startsWith('/reminders')) return 'reminders';
  if (path.startsWith('/settings') || path.startsWith('/services') || path.startsWith('/staff') || path.startsWith('/working-hours')) return 'settings';
  if (path.startsWith('/profile')) return 'profile';
  return 'home';
}

export function MobileLayout() {
  const { pathname } = useLocation();
  const active = activeTab(pathname);

  return (
    <div className="mobile-app">
      <main className="mobile-main"><Outlet /></main>
      <div className="m-tabbar">
        {tabs.map((tab) => {
          const Icon = Icons[tab.icon];
          return (
            <Link key={tab.id} to={tab.href} className={`tab ${active === tab.id ? 'active' : ''}`}>
              <span className="icon"><Icon size={20} /></span>
              <span>{tab.label}</span>
            </Link>
          );
        })}
      </div>
    </div>
  );
}
