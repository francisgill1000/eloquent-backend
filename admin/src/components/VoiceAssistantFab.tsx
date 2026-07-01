import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';

/** Floating mic on every tabbed screen → opens the voice assistant page. */
export function VoiceAssistantFab() {
  const navigate = useNavigate();
  return (
    <button className="va-fab" aria-label="Voice assistant" onClick={() => navigate('/ask')}>
      <Icons.Mic size={22} />
    </button>
  );
}
