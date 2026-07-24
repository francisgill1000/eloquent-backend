import { Link, Outlet, useLocation } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { WHATSAPP_ENABLED } from '@/lib/features';
import { type Module } from '@/lib/modules';
import { isLeadAgent, navAllowed, visibleSettingsOptions, type Perm } from '@/lib/nav';

type Tab = { id: string; label: string; href: string; icon: keyof typeof Icons; modules: Module[]; perm?: Perm };

const BOTH: Module[] = ['bookings', 'leads'];

const ALL_TABS: Tab[] = [
  { id: 'ai-summary', label: 'Summary', href: '/ai-summary', icon: 'Sparkle', modules: BOTH, perm: 'summary.view' },
  { id: 'home', label: 'Home', href: '/', icon: 'Home', modules: BOTH, perm: 'assistant.use' },
  // Past conversations with the Ask assistant.
  { id: 'conversations', label: 'Chats', href: '/conversations', icon: 'Chat', modules: BOTH, perm: 'chats.view' },
  { id: 'bookings', label: 'Bookings', href: '/bookings', icon: 'Calendar', modules: ['bookings'], perm: 'bookings.view' },
  // WhatsApp Chats — hidden temporarily behind WHATSAPP_ENABLED.
  { id: 'chats', label: 'Chats', href: '/chats', icon: 'Chat', modules: BOTH },
  // Reminders tab hidden for now — page still reachable at /reminders.
  { id: 'settings', label: 'Settings', href: '/settings', icon: 'Sliders', modules: BOTH },
  { id: 'profile', label: 'Profile', href: '/profile', icon: 'Store', modules: BOTH, perm: 'profile.view' },
];
// A master is the operator account — a single "All Businesses" tab, not the
// shop-operational tabs.
const MASTER_TABS: Tab[] = [{ id: 'master', label: 'All Businesses', href: '/master', icon: 'Key', modules: BOTH }];

function activeTab(path: string): string {
  if (path === '/') return 'home';
  if (path.startsWith('/conversations')) return 'conversations';
  if (path.startsWith('/ai-summary')) return 'ai-summary';
  if (path.startsWith('/bookings') || path.startsWith('/booking')) return 'bookings';
  if (path.startsWith('/chats')) return 'chats';
  if (path.startsWith('/reminders')) return 'reminders';
  if (path.startsWith('/settings') || path.startsWith('/services') || path.startsWith('/staff') || path.startsWith('/working-hours') || path.startsWith('/customers')) return 'settings';
  if (path.startsWith('/profile')) return 'profile';
  // Leads lives under the Settings group on mobile (reached via the Settings list).
  if (path.startsWith('/leads')) return 'settings';
  return 'home';
}

export function MobileLayout() {
  const { pathname } = useLocation();
  const { shop, can } = useShop();
  const active = shop?.is_master ? 'master' : activeTab(pathname);
  const tabs = shop?.is_master
    ? MASTER_TABS
    : ALL_TABS.filter(
        (t) =>
          (WHATSAPP_ENABLED || t.id !== 'chats') &&
          // The shop-wide AI summary is hidden from lead agents.
          !(t.href === '/ai-summary' && isLeadAgent(shop, can)) &&
          // Settings is a container: show only if ≥1 of its items is visible.
          (t.id === 'settings' ? visibleSettingsOptions(shop, can).length > 0 : navAllowed(t, shop, can)),
      );

  return (
    <div className="mobile-app">
      <main className="mobile-main"><Outlet /></main>
      {/* Size the grid to the actual tab count so the bar stays a single row
          however many tabs a shop has (5, the new 6 with Chats, or a master's 1). */}
      <div className="m-tabbar" style={{ gridTemplateColumns: `repeat(${tabs.length}, 1fr)` }}>
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
