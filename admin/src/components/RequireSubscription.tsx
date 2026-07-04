import { Navigate, Outlet } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';
import { useSubscription } from '@/context/SubscriptionContext';
import { Spinner } from './Spinner';

/**
 * Gate the whole app behind an active subscription. The master account is
 * always exempt. Lapsed shops are sent to /subscribe.
 */
export default function RequireSubscription() {
  const { shop } = useShop();
  const { sub, loading } = useSubscription();

  if (shop?.is_master) return <Outlet />;
  if (loading) return <Spinner label="Loading…" />;

  // Only block on a definitively-expired subscription. If the status is unknown
  // (fetch failed), fail open — the backend gate still returns 402 on real data
  // calls, which redirects here anyway. This avoids booting a valid trial user.
  if (sub && sub.status !== 'trialing' && sub.status !== 'active') {
    return <Navigate to="/subscribe" replace />;
  }
  return <Outlet />;
}
