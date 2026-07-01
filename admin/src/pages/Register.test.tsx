import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import * as shops from '@/lib/shops';
import Register from './Register';

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
  vi.spyOn(shops, 'getServiceCategories').mockResolvedValue(CATEGORIES);
  return render(<MemoryRouter><ShopProvider><Register /></ShopProvider></MemoryRouter>);
}

describe('Register', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); nav.mockReset(); });

  it('submits name, phone and category, then shows the new Business ID and PIN', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({
      shop: { id: 1, name: 'Acme', shop_code: '482913', pin: '7204' },
      token: 'fresh-token',
    });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.type(screen.getByLabelText(/phone/i), '0501234567');
    await user.selectOptions(await screen.findByLabelText(/service category/i), '9');
    await user.click(screen.getByRole('button', { name: /register business/i }));
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Acme Salon',
      phone: '0501234567',
      category_id: 9,
    }));
    expect(await screen.findByText(/business registered/i)).toBeInTheDocument();
    expect(screen.getByText('482913')).toBeInTheDocument();
    expect(screen.getByText('7204')).toBeInTheDocument();

    // continue logs straight in and lands on the dashboard
    await user.click(screen.getByRole('button', { name: /continue to dashboard/i }));
    expect(nav).toHaveBeenCalledWith('/');
    expect(localStorage.getItem('shop_token')).toBe('fresh-token');
  });

  it('blocks submit without a business name', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /register business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/business name is required/i)).toBeInTheDocument();
  });

  it('blocks submit without a phone number', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.click(screen.getByRole('button', { name: /register business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/phone number is required/i)).toBeInTheDocument();
  });

  it('blocks submit without a category', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.type(screen.getByLabelText(/phone/i), '0501234567');
    await user.click(screen.getByRole('button', { name: /register business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/choose your service category/i)).toBeInTheDocument();
  });
});
