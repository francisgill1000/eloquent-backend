import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { ShopProvider, useShop } from './ShopContext';
import { storage } from '@/lib/storage';

function Probe() {
  const { shop, token, loginShop, logoutShop } = useShop();
  return (
    <div>
      <span data-testid="name">{shop?.name ?? 'none'}</span>
      <span data-testid="token">{token ?? 'none'}</span>
      <button onClick={() => loginShop({ id: 1, name: 'Acme' }, 'tok')}>login</button>
      <button onClick={() => logoutShop()}>logout</button>
    </div>
  );
}

describe('ShopContext', () => {
  beforeEach(() => localStorage.clear());

  it('hydrates from storage', () => {
    storage.setJSON('shop_data', { id: 9, name: 'Saved' });
    storage.set('shop_token', 'persisted');
    render(<ShopProvider><Probe /></ShopProvider>);
    expect(screen.getByTestId('name').textContent).toBe('Saved');
    expect(screen.getByTestId('token').textContent).toBe('persisted');
  });

  it('login then logout updates state and storage', () => {
    render(<ShopProvider><Probe /></ShopProvider>);
    act(() => screen.getByText('login').click());
    expect(screen.getByTestId('name').textContent).toBe('Acme');
    expect(storage.get('shop_token')).toBe('tok');
    act(() => screen.getByText('logout').click());
    expect(screen.getByTestId('name').textContent).toBe('none');
    expect(storage.get('shop_token')).toBeNull();
  });
});
