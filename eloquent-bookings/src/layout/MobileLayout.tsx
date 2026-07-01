import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useVoiceSearch } from '@/context/VoiceSearchContext';

type Tab = { id: string; label: string; href: string; icon: keyof typeof Icons };

// The centre item is the voice mic (a press-to-talk button, not a link); the
// other four flank it 2+2. (Near Me lives on the Home header; the AI finder
// handles "near me" queries directly.)
const tabs: Tab[] = [
  { id: 'home', label: 'Home', href: '/', icon: 'Home' },
  { id: 'bookings', label: 'Bookings', href: '/bookings', icon: 'Calendar' },
  { id: 'ai', label: 'AI', href: '/ai', icon: 'Mic' },
  { id: 'favourites', label: 'Favourites', href: '/favourites', icon: 'Heart' },
  { id: 'account', label: 'Account', href: '/account', icon: 'User' },
];

function activeTab(path: string): string {
  if (path === '/' || path.startsWith('/shop')) return 'home';
  if (path.startsWith('/bookings') || path.startsWith('/booking')) return 'bookings';
  if (path.startsWith('/ai')) return 'ai';
  if (path.startsWith('/favourites')) return 'favourites';
  if (path.startsWith('/account') || path.startsWith('/login') || path.startsWith('/register')) return 'account';
  return 'home';
}

export function MobileLayout() {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const voice = useVoiceSearch();
  const active = activeTab(pathname);

  // The centre mic: open the AI screen (if not there) and start listening. On
  // browsers without speech, just open AI — it shows a text input instead.
  const onMic = () => {
    if (pathname !== '/ai') navigate('/ai');
    if (voice.supported) voice.toggleListening();
  };

  return (
    <div className="mobile-app">
      <main className="mobile-main"><Outlet /></main>
      <div className="m-tabbar">
        {tabs.map((tab) => {
          const Icon = Icons[tab.icon];
          const isActive = active === tab.id;
          if (tab.id === 'ai') {
            return (
              <button
                key={tab.id}
                type="button"
                className={`tab tab-ai ${isActive ? 'active' : ''} ${voice.listening ? 'listening' : ''}`}
                onClick={onMic}
                aria-label={voice.listening ? 'Stop listening' : 'Voice search'}
              >
                <span className="tab-ai-orb">
                  <span className="tab-ai-ring" aria-hidden="true" />
                  <span className="tab-ai-ring" aria-hidden="true" />
                  {voice.sending ? <span className="tab-ai-spin" aria-hidden="true" /> : <Icons.Mic size={24} />}
                </span>
              </button>
            );
          }
          return (
            <Link key={tab.id} to={tab.href} className={`tab ${isActive ? 'active' : ''}`}>
              <span className="icon"><Icon size={20} /></span>
              <span>{tab.label}</span>
            </Link>
          );
        })}
      </div>
    </div>
  );
}
