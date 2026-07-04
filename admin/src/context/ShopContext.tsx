import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { storage } from '@/lib/storage';
import type { Shop, AuthUser } from '@/types';

type ShopContextValue = {
  shop: Shop | null;
  token: string | null;
  loading: boolean;
  /** The logged-in user (null for a legacy/owner-equivalent session). */
  currentUser: AuthUser | null;
  /** Effective permission names; ['*'] means all (owner). */
  permissions: string[];
  /** True if the current user holds the given permission (owner = always). */
  can: (permission: string) => boolean;
  loginShop: (shop: Shop, token: string) => void;
  /** Persist the acting user + permissions returned at login / from /auth/me. */
  setAccess: (user: AuthUser | null, permissions: string[]) => void;
  logoutShop: () => void;
};

const ShopContext = createContext<ShopContextValue | null>(null);

export function ShopProvider({ children }: { children: ReactNode }) {
  const [shop, setShop] = useState<Shop | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [currentUser, setCurrentUser] = useState<AuthUser | null>(null);
  const [permissions, setPermissions] = useState<string[]>([]);

  useEffect(() => {
    const saved = storage.getJSON<Shop>('shop_data');
    const savedToken = storage.get('shop_token');
    if (saved && savedToken) {
      setShop(saved);
      setToken(savedToken);
      setCurrentUser(storage.getJSON<AuthUser>('shop_user'));
      setPermissions(storage.getJSON<string[]>('shop_permissions') ?? []);
    }
    setLoading(false);
  }, []);

  const loginShop = (s: Shop, t: string) => {
    setShop(s);
    setToken(t);
    storage.setJSON('shop_data', s);
    storage.set('shop_token', t);
  };

  const setAccess = (user: AuthUser | null, perms: string[]) => {
    setCurrentUser(user);
    setPermissions(perms);
    storage.setJSON('shop_user', user);
    storage.setJSON('shop_permissions', perms);
  };

  const can = (permission: string) =>
    permissions.includes('*') || permissions.includes(permission);

  const logoutShop = () => {
    setShop(null);
    setToken(null);
    setCurrentUser(null);
    setPermissions([]);
    storage.remove('shop_data');
    storage.remove('shop_token');
    storage.remove('shop_user');
    storage.remove('shop_permissions');
  };

  return (
    <ShopContext.Provider
      value={{ shop, token, loading, currentUser, permissions, can, loginShop, setAccess, logoutShop }}
    >
      {children}
    </ShopContext.Provider>
  );
}

export function useShop(): ShopContextValue {
  const ctx = useContext(ShopContext);
  if (!ctx) throw new Error('useShop must be used inside ShopProvider');
  return ctx;
}
