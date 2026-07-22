import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as shops from '@/lib/shops';
import MasterShopCreate from './MasterShopCreate';

const nav = vi.fn();
vi.mock('react-router-dom', async (orig) => ({
  ...(await orig() as object),
  useNavigate: () => nav,
}));

const CATEGORIES = [
  { id: 1, name: 'Barber' },
  { id: 9, name: 'Salon' },
];

function setup() {
  storage.setJSON('shop_data', { id: 1, name: 'Business Lens HQ', is_master: true });
  storage.set('shop_token', 'tok');
  vi.spyOn(shops, 'getServiceCategories').mockResolvedValue(CATEGORIES);
  return render(<MemoryRouter><ShopProvider><MasterShopCreate /></ShopProvider></MemoryRouter>);
}

describe('MasterShopCreate', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); nav.mockReset(); });

  it('submits name, email, password and category, then shows the new credentials', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({
      shop: { id: 1, name: 'Acme', email: 'acme@example.com' },
      token: 'fresh-token',
    });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.type(screen.getByLabelText(/phone/i), '0501234567');
    await user.type(screen.getByLabelText(/^email$/i), 'acme@example.com');
    await user.type(screen.getByLabelText(/password/i), 'a-strong-pass');
    await user.selectOptions(await screen.findByLabelText(/service category/i), '9');
    await user.click(screen.getByRole('button', { name: /create business/i }));
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Acme Salon',
      phone: '0501234567',
      email: 'acme@example.com',
      password: 'a-strong-pass',
      category_id: 9,
    }));
    expect(await screen.findByText(/created ✓/i)).toBeInTheDocument();
    expect(screen.getByText('acme@example.com')).toBeInTheDocument();
    expect(screen.getByText('a-strong-pass')).toBeInTheDocument();
  });

  it('blocks submit without a business name', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /create business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/business name is required/i)).toBeInTheDocument();
  });

  it('blocks submit without an email', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.click(screen.getByRole('button', { name: /create business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/email is required/i)).toBeInTheDocument();
  });

  it('blocks submit with a short password', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.type(screen.getByLabelText(/^email$/i), 'acme@example.com');
    await user.type(screen.getByLabelText(/password/i), 'short');
    await user.click(screen.getByRole('button', { name: /create business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/at least 8 characters/i)).toBeInTheDocument();
  });

  it('redirects non-master shops away', () => {
    storage.setJSON('shop_data', { id: 2, name: 'Some Shop', is_master: false });
    storage.set('shop_token', 'tok');
    render(<MemoryRouter><ShopProvider><MasterShopCreate /></ShopProvider></MemoryRouter>);
    expect(nav).toHaveBeenCalledWith('/');
  });
});
