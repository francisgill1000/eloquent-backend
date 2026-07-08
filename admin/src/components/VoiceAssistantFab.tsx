import { useNavigate, useLocation } from 'react-router-dom';
import { Icons } from '@/components/Icons';

/** Floating mic on every authenticated screen → opens the voice assistant page. */
export function VoiceAssistantFab() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  // Don't show the mic on the assistant itself — the home/new-chat screen (`/`,
  // `/ask`) or an open conversation (`/ask/:id`). Otherwise it overlaps the
  // chat composer.
  if (pathname === '/' || pathname === '/ask' || pathname.startsWith('/ask/')) return null;
  return (
    <button className="va-fab" aria-label="Voice assistant" onClick={() => navigate('/ask')}>
      <Icons.Mic size={22} />
    </button>
  );
}
