import { Navigate } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';
import { firstAccessiblePath } from '@/lib/nav';
import VoiceAssistant from '@/pages/VoiceAssistant';

/**
 * The home screen is the Ask assistant. If the acting user lacks `assistant.use`,
 * their Home nav item is hidden — so send them to the first section they CAN
 * access instead of landing them on a blocked Home.
 */
export function Landing() {
  const { shop, can } = useShop();
  if (!can('assistant.use')) {
    const to = firstAccessiblePath(shop, can);
    if (to !== '/') return <Navigate to={to} replace />;
  }
  return <VoiceAssistant />;
}
