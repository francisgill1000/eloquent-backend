import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as shops from '@/lib/shops';
import CategorySetup from './CategorySetup';

const nav = vi.fn();
vi.mock('react-router-dom', async (orig) => ({
  ...(await orig() as object),
  useNavigate: () => nav,
}));

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  vi.spyOn(shops, 'getServiceCategories').mockResolvedValue([
    { id: 1, name: 'Barber' },
    { id: 9, name: 'Salon' },
  ]);
  return render(<MemoryRouter><ShopProvider><CategorySetup /></ShopProvider></MemoryRouter>);
}

describe('CategorySetup', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); nav.mockReset(); });

  it('saves the one-time category and refreshes the stored shop', async () => {
    const confirm = vi.spyOn(shops, 'confirmShopCategory').mockResolvedValue({
      id: 7, name: 'Acme', category_id: 9, category_confirmed_at: '2026-06-07T00:00:00Z',
    });

    setup();
    const user = userEvent.setup();
    await user.selectOptions(await screen.findByLabelText(/service category/i), '9');
    await user.click(screen.getByRole('button', { name: /save category/i }));

    expect(confirm).toHaveBeenCalledWith(9);
    expect(nav).toHaveBeenCalledWith('/');
    expect(storage.getJSON<{ category_id?: number }>('shop_data')?.category_id).toBe(9);
  });

  it('requires a selection', async () => {
    const confirm = vi.spyOn(shops, 'confirmShopCategory');
    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /save category/i }));
    expect(await screen.findByText(/choose your service category/i)).toBeInTheDocument();
    expect(confirm).not.toHaveBeenCalled();
  });
});
