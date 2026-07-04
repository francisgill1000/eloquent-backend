import { createContext, useContext, useEffect, useState, useCallback, type ReactNode } from 'react';
import { getSubscription, type SubStatus } from '@/lib/subscription';
import { useShop } from './ShopContext';

type Ctx = { sub: SubStatus | null; loading: boolean; refresh: () => Promise<void> };

const SubscriptionContext = createContext<Ctx>({ sub: null, loading: true, refresh: async () => {} });

export function SubscriptionProvider({ children }: { children: ReactNode }) {
  const { shop } = useShop();
  const [sub, setSub] = useState<SubStatus | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    // Shop still resolving from storage — stay in the loading state so the gate
    // shows a spinner rather than briefly seeing no subscription and redirecting.
    if (!shop) { setSub(null); return; }
    if (shop.is_master) { setSub(null); setLoading(false); return; }
    setLoading(true);
    try { setSub(await getSubscription()); } catch { /* leave as-is */ } finally { setLoading(false); }
  }, [shop]);

  useEffect(() => { void refresh(); }, [refresh]);

  return <SubscriptionContext.Provider value={{ sub, loading, refresh }}>{children}</SubscriptionContext.Provider>;
}

export const useSubscription = () => useContext(SubscriptionContext);
