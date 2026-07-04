import type { ReactNode } from 'react';
import { useShop } from '@/context/ShopContext';

/**
 * Renders children only when the current user holds `permission`. Owners
 * (permissions = ['*']) always pass. Backend enforcement is the real gate —
 * this only hides UI the user cannot act on.
 */
export function Can({
  permission,
  children,
  fallback = null,
}: {
  permission: string;
  children: ReactNode;
  fallback?: ReactNode;
}) {
  const { can } = useShop();
  return <>{can(permission) ? children : fallback}</>;
}
