import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as lib from '@/lib/shops';
import type { MasterShop } from '@/types';
import MasterShopDetail from './MasterShopDetail';

const shop: MasterShop = {
  id: 7, name: 'Shakaina Salon', shop_code: '339416', email: 'owner@shakaina.com',
  phone: '+971500000000', category: 'Salon', location: 'Dubai', status: 'active',
  persona: '', bookings_count: 12, wa_connected: true, created_at: '2026-06-07T00:00:00Z',
};

function setup(state: { shop: MasterShop } = { shop }) {
  storage.setJSON('shop_data', { id: 1, name: 'Business Lens HQ', is_master: true });
  storage.set('shop_token', 'tok');
  return render(
    <MemoryRouter initialEntries={[{ pathname: '/master/7', state }]}>
      <ShopProvider>
        <Routes>
          <Route path="/master/:id" element={<MasterShopDetail />} />
        </Routes>
      </ShopProvider>
    </MemoryRouter>,
  );
}

describe('MasterShopDetail', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('shows credentials and activity from router state', () => {
    setup();
    expect(screen.getByText('Shakaina Salon')).toBeInTheDocument();
    expect(screen.getByDisplayValue('owner@shakaina.com')).toBeInTheDocument();
    expect(screen.getByText(/12 bookings/)).toBeInTheDocument();
  });

  it('saves the email via updateMasterShop', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop, email: 'new@shakaina.com' });
    setup();
    const user = userEvent.setup();
    const emailInput = screen.getByLabelText(/^email$/i);
    await user.clear(emailInput);
    await user.type(emailInput, 'new@shakaina.com');
    await user.click(screen.getByRole('button', { name: /save email/i }));
    expect(update).toHaveBeenCalledWith(7, { email: 'new@shakaina.com' });
  });

  it('sets a new password and shows it once for copying', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/new password/i), 'brand-new-pass');
    await user.click(screen.getByRole('button', { name: /set password/i }));
    expect(update).toHaveBeenCalledWith(7, { password: 'brand-new-pass' });
    expect(await screen.findByText('brand-new-pass')).toBeInTheDocument();
  });

  it('saves a persona via updateMasterShop', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop, persona: 'You are Shakaina Salon.' });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/persona/i), 'You are Shakaina Salon.');
    await user.click(screen.getByRole('button', { name: /save persona/i }));
    expect(update).toHaveBeenCalledWith(7, { persona: 'You are Shakaina Salon.' });
  });

  it('toggles visibility via updateMasterShop', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop, status: 'inactive' });
    setup();
    await userEvent.setup().click(screen.getByRole('button', { name: /hide from customer app/i }));
    expect(update).toHaveBeenCalledWith(7, { status: 'inactive' });
  });

  it('toggling Business Hunt sends the updated modules', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop, modules: ['bookings', 'leads'] });
    setup();
    await userEvent.setup().click(screen.getByRole('button', { name: /enable business hunt/i }));
    expect(update).toHaveBeenCalledWith(7, { modules: ['bookings', 'leads'] });
  });
});
