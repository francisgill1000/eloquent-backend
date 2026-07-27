import { useShop } from '@/context/ShopContext';
import { HuntDashboard } from '@/components/HuntDashboard';
import { homeShowsDashboard } from '@/lib/nav';
import VoiceAssistant from '@/pages/VoiceAssistant';

/**
 * The home screen, composed per section rather than gated as a whole page.
 *
 * A Hunt shop lands on its overview — the numbers are what you want first thing.
 * A bookings-only shop has no Hunt dashboard to show, so Home stays the Ask
 * assistant it has always been. Gating the route itself would bounce one of
 * those two groups off their own home page, which is why `/` carries no
 * RequirePerm (unlike `/ask`, which is still assistant-only).
 *
 * The mic is still reachable from anywhere via VoiceAssistantFab.
 */
export function Landing() {
  const { shop, can } = useShop();

  if (homeShowsDashboard(shop, can)) return <HuntDashboard />;
  if (can('assistant.use')) return <VoiceAssistant />;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">No access</h1>
        <p className="c-page-sub">
          Your role doesn’t include any sections yet. Ask the business owner to grant you access.
        </p>
      </div>
    </div></div>
  );
}
