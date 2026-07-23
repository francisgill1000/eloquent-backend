import { Navigate, Outlet } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';
import { useSubscription } from '@/context/SubscriptionContext';
import { shopHasModule } from '@/lib/modules';
import { Spinner } from './Spinner';

/**
 * Gate the whole app behind an active subscription. The master account is
 * always exempt. Lapsed shops are sent to /subscribe.
 *
 * Business Hunt is exempt too: Hunt is billed by credits, a separate meter from
 * the Lens subscription, and the backend deliberately leaves the /shop/leads/*
 * routes outside `subscription.active` (see routes/api.php). A Hunt-only shop
 * with a lapsed Lens sub must still reach its Hunt pages, so a shop that has
 * `leads` but not `bookings` never sees this gate.
 */
export default function RequireSubscription() {
  const { shop } = useShop();
  const { sub, loading } = useSubscription();

  if (shop?.is_master) return <Outlet />;
  if (shopHasModule(shop, 'leads') && !shopHasModule(shop, 'bookings')) return <Outlet />;
  if (loading) return <Spinner label="Loading…" />;

  // Only block on a definitively-expired subscription. If the status is unknown
  // (fetch failed), fail open — the backend gate still returns 402 on real data
  // calls, which redirects here anyway. This avoids booting a valid trial user.
  if (sub && sub.status !== 'trialing' && sub.status !== 'active') {
    return <Navigate to="/subscribe" replace />;
  }
  return <Outlet />;
}
