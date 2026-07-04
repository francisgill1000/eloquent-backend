import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';

/**
 * Voice-first home: a single animated mic. Tapping it opens the Ask assistant.
 * The dashboard now lives on /overview.
 */
export default function Home() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const firstName = (shop?.name || '').trim().split(/\s+/)[0];

  return (
    <div className="home-mic-screen">
      <div className="home-mic-wrap">
        <p className="home-mic-greeting">{firstName ? `Hi, ${firstName}` : 'Hi there'}</p>
        <button className="home-mic" aria-label="Ask your assistant" onClick={() => navigate('/ask')}>
          <span className="home-mic-ring" aria-hidden />
          <span className="home-mic-ring" aria-hidden />
          <span className="home-mic-ring" aria-hidden />
          <span className="home-mic-core"><Icons.Mic size={44} /></span>
        </button>
        <p className="home-mic-hint">Tap to ask anything</p>
      </div>
    </div>
  );
}
