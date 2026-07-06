import { Navigate, Outlet } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';
import { shopHasModule, type Module } from '@/lib/modules';

/** Route gate: renders children only if the shop has `module`, else redirects
 *  to Home. Master passes (shopHasModule returns true for masters). */
export function ModuleGuard({ module }: { module: Module }) {
  const { shop } = useShop();
  if (!shopHasModule(shop, module)) return <Navigate to="/" replace />;
  return <Outlet />;
}
