import { Link } from 'react-router-dom';
import { useSubscription } from '@/context/SubscriptionContext';

/** Only nudge in the final stretch of the trial — silent before that. */
const NUDGE_WINDOW_DAYS = 5;

/**
 * In-app renewal nudge. Stays silent for most of the trial and only appears in
 * the final few days (escalating as expiry nears), so a new shop enjoys the
 * trial without being pushed to pay on day 1. v1 reminder — no outbound msgs.
 */
export default function TrialBanner() {
  const { sub } = useSubscription();
  if (!sub || sub.status !== 'trialing' || sub.days_left > NUDGE_WINDOW_DAYS) return null;

  const urgent = sub.days_left <= 3;
  return (
    <div className={`c-trial-banner${urgent ? ' urgent' : ''}`}>
      <span>{sub.days_left} {sub.days_left === 1 ? 'day' : 'days'} left in your free trial</span>
      <Link to="/subscribe" className="c-btn" style={{ padding: '6px 12px', fontSize: 13 }}>Subscribe</Link>
    </div>
  );
}
