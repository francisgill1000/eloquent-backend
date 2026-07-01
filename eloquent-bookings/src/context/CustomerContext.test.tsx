import { describe, it, expect } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { CustomerProvider, useCustomer } from './CustomerContext';

function Probe() {
  const { customer, loginCustomer, logoutCustomer } = useCustomer();
  return (
    <div>
      <span data-testid="name">{customer?.name ?? 'guest'}</span>
      <button onClick={() => loginCustomer({ id: 1, name: 'Ada', phone: '050' }, 'tok')}>login</button>
      <button onClick={() => logoutCustomer()}>logout</button>
    </div>
  );
}

describe('CustomerContext', () => {
  it('hydrates from localStorage on mount', () => {
    localStorage.setItem('customer_data', JSON.stringify({ id: 9, name: 'Stored', phone: '1' }));
    localStorage.setItem('customer_token', 'abc');
    render(<CustomerProvider><Probe /></CustomerProvider>);
    expect(screen.getByTestId('name').textContent).toBe('Stored');
  });

  it('login then logout updates state and storage', () => {
    render(<CustomerProvider><Probe /></CustomerProvider>);
    expect(screen.getByTestId('name').textContent).toBe('guest');
    act(() => { screen.getByText('login').click(); });
    expect(screen.getByTestId('name').textContent).toBe('Ada');
    expect(localStorage.getItem('customer_token')).toBe('tok');
    act(() => { screen.getByText('logout').click(); });
    expect(screen.getByTestId('name').textContent).toBe('guest');
    expect(localStorage.getItem('customer_token')).toBeNull();
  });
});
