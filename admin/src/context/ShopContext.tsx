import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { storage } from '@/lib/storage';
import type { Shop } from '@/types';

type ShopContextValue = {
  shop: Shop | null;
  token: string | null;
  loading: boolean;
  loginShop: (shop: Shop, token: string) => void;
  logoutShop: () => void;
};

const ShopContext = createContext<ShopContextValue | null>(null);

export function ShopProvider({ children }: { children: ReactNode }) {
  const [shop, setShop] = useState<Shop | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const saved = storage.getJSON<Shop>('shop_data');
    const savedToken = storage.get('shop_token');
    if (saved && savedToken) {
      setShop(saved);
      setToken(savedToken);
    }
    setLoading(false);
  }, []);

  const loginShop = (s: Shop, t: string) => {
    setShop(s);
    setToken(t);
    storage.setJSON('shop_data', s);
    storage.set('shop_token', t);
  };

  const logoutShop = () => {
    setShop(null);
    setToken(null);
    storage.remove('shop_data');
    storage.remove('shop_token');
  };

  return (
    <ShopContext.Provider value={{ shop, token, loading, loginShop, logoutShop }}>
      {children}
    </ShopContext.Provider>
  );
}

export function useShop(): ShopContextValue {
  const ctx = useContext(ShopContext);
  if (!ctx) throw new Error('useShop must be used inside ShopProvider');
  return ctx;
}
