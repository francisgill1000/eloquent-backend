import { Link } from 'react-router-dom';
import { useSubscription } from '@/context/SubscriptionContext';

/**
 * In-app renewal nudge. Shows only during the trial; escalates in the final
 * few days. This is the v1 reminder mechanism — no outbound messaging.
 */
export default function TrialBanner() {
  const { sub } = useSubscription();
  if (!sub || sub.status !== 'trialing') return null;

  const urgent = sub.days_left <= 3;
  return (
    <div className={`c-trial-banner${urgent ? ' urgent' : ''}`}>
      <span>{sub.days_left} {sub.days_left === 1 ? 'day' : 'days'} left in your free trial</span>
      <Link to="/subscribe" className="c-btn" style={{ padding: '6px 12px', fontSize: 13 }}>Subscribe</Link>
    </div>
  );
}
