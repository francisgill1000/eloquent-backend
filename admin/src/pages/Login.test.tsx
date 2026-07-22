import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import * as shops from '@/lib/shops';
import Login from './Login';

const nav = vi.fn();
vi.mock('react-router-dom', async (orig) => ({
  ...(await orig() as object),
  useNavigate: () => nav,
}));

function setup() {
  return render(<MemoryRouter><ShopProvider><Login /></ShopProvider></MemoryRouter>);
}

describe('Login', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); nav.mockReset(); });

  it('logs in with email and password', async () => {
    vi.spyOn(shops, 'shopLogin').mockResolvedValue({ token: 't', shop: { id: 1, name: 'Acme' }, user: null, permissions: ['*'] });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/email/i), 'owner@example.com');
    await user.type(screen.getByLabelText(/password/i), 'secret123');
    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(shops.shopLogin).toHaveBeenCalledWith('owner@example.com', 'secret123');
    expect(localStorage.getItem('shop_token')).toBe('t');
  });

  it('shows an error on failed login', async () => {
    vi.spyOn(shops, 'shopLogin').mockRejectedValue(new Error('bad'));
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/email/i), 'x@example.com');
    await user.type(screen.getByLabelText(/password/i), 'wrong');
    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(await screen.findByText(/failed|invalid|incorrect/i)).toBeInTheDocument();
  });

  it('remembers only the email, never the password', async () => {
    vi.spyOn(shops, 'shopLogin').mockResolvedValue({ token: 't', shop: { id: 1, name: 'Acme' }, user: null, permissions: ['*'] });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/email/i), 'owner@example.com');
    await user.type(screen.getByLabelText(/password/i), 'secret123');
    await user.click(screen.getByLabelText(/remember/i));
    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(localStorage.getItem('remember_shop_email')).toBe('owner@example.com');
    expect(Object.keys(localStorage).some((k) => localStorage.getItem(k) === 'secret123')).toBe(false);
  });
});
