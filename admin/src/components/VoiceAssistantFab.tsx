import { useNavigate, useLocation } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { homeShowsDashboard } from '@/lib/nav';

/** Floating mic on every authenticated screen → opens the voice assistant page. */
export function VoiceAssistantFab() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const { shop, can } = useShop();

  // /ask is gated on assistant.use, so without that permission this button
  // would only ever bounce the user straight back. Don't offer it at all.
  if (!can('assistant.use')) return null;

  // Hide the mic where it would overlap content instead of helping:
  //  - the assistant itself (`/ask`, `/ask/:id`)
  //  - Home, but ONLY when Home is rendering the assistant inline. On a Hunt
  //    shop Home is the dashboard, and the mic is how you reach the assistant
  //    from it.
  //  - the booking detail page (`/booking/:id`), a focused page with no bottom
  //    tab bar, where the FAB (positioned above the tab bar) floats over the
  //    Save / Assign buttons.
  if (
    (pathname === '/' && !homeShowsDashboard(shop, can)) ||
    pathname === '/ask' || pathname.startsWith('/ask/') ||
    pathname.startsWith('/booking/')
  ) return null;
  return (
    <button className="va-fab" aria-label="Voice assistant" onClick={() => navigate('/ask')}>
      <Icons.Mic size={22} />
    </button>
  );
}
