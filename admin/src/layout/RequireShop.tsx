import { Navigate, Outlet } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';

export function RequireShop() {
  const { shop, token, loading } = useShop();
  if (loading) return null;
  if (!shop || !token) return <Navigate to="/login" replace />;
  return <Outlet />;
}
