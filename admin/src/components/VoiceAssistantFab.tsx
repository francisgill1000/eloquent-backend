import { useNavigate, useLocation } from 'react-router-dom';
import { Icons } from '@/components/Icons';

/** Floating mic on every authenticated screen → opens the voice assistant page. */
export function VoiceAssistantFab() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  // Don't show the mic on the assistant page itself, or on the mic home page
  // (both already lead to / are the assistant).
  if (pathname === '/ask' || pathname === '/') return null;
  return (
    <button className="va-fab" aria-label="Voice assistant" onClick={() => navigate('/ask')}>
      <Icons.Mic size={22} />
    </button>
  );
}
