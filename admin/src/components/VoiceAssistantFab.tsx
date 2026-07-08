import { useNavigate, useLocation } from 'react-router-dom';
import { Icons } from '@/components/Icons';

/** Floating mic on every authenticated screen → opens the voice assistant page. */
export function VoiceAssistantFab() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  // Hide the mic where it would overlap content instead of helping:
  //  - the assistant itself (`/`, `/ask`, `/ask/:id`)
  //  - the booking detail page (`/booking/:id`), a focused page with no bottom
  //    tab bar, where the FAB (positioned above the tab bar) floats over the
  //    Save / Assign buttons.
  if (
    pathname === '/' ||
    pathname === '/ask' || pathname.startsWith('/ask/') ||
    pathname.startsWith('/booking/')
  ) return null;
  return (
    <button className="va-fab" aria-label="Voice assistant" onClick={() => navigate('/ask')}>
      <Icons.Mic size={22} />
    </button>
  );
}
