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

  it('logs in with Business ID and PIN on one page', async () => {
    vi.spyOn(shops, 'shopLogin').mockResolvedValue({ token: 't', shop: { id: 1, name: 'Acme' }, user: null, permissions: ['*'] });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business id/i), 'ACME01');
    await user.type(screen.getByLabelText('PIN'), '1234');
    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(shops.shopLogin).toHaveBeenCalledWith('ACME01', '1234');
    expect(localStorage.getItem('shop_token')).toBe('t');
  });

  it('shows an error on failed login', async () => {
    vi.spyOn(shops, 'shopLogin').mockRejectedValue(new Error('bad'));
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business id/i), 'X');
    await user.type(screen.getByLabelText('PIN'), '0000');
    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(await screen.findByText(/failed|invalid|incorrect/i)).toBeInTheDocument();
  });
});
