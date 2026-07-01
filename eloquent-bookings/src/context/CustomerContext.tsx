import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { storage } from '@/lib/storage';

export type Customer = {
  id: number;
  name: string;
  phone?: string;
  email?: string;
  [key: string]: unknown;
};

type CustomerContextValue = {
  customer: Customer | null;
  customerToken: string | null;
  loading: boolean;
  loginCustomer: (user: Customer, token: string) => void;
  logoutCustomer: () => void;
};

const CustomerContext = createContext<CustomerContextValue | null>(null);

export function CustomerProvider({ children }: { children: ReactNode }) {
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [customerToken, setCustomerToken] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const user = storage.getJSON<Customer>('customer_data');
    const token = storage.get('customer_token');
    if (user && token) {
      setCustomer(user);
      setCustomerToken(token);
    }
    setLoading(false);
  }, []);

  const loginCustomer = (user: Customer, token: string) => {
    setCustomer(user);
    setCustomerToken(token);
    storage.setJSON('customer_data', user);
    storage.set('customer_token', token);
  };

  const logoutCustomer = () => {
    setCustomer(null);
    setCustomerToken(null);
    storage.remove('customer_data');
    storage.remove('customer_token');
  };

  return (
    <CustomerContext.Provider value={{ customer, customerToken, loading, loginCustomer, logoutCustomer }}>
      {children}
    </CustomerContext.Provider>
  );
}

export function useCustomer(): CustomerContextValue {
  const ctx = useContext(CustomerContext);
  if (!ctx) throw new Error('useCustomer must be used inside CustomerProvider');
  return ctx;
}
