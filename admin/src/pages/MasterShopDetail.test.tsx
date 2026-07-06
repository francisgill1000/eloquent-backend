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
  id: 7, name: 'Shakaina Salon', shop_code: '339416', pin: '7201',
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
    expect(screen.getByText('339416')).toBeInTheDocument();
    expect(screen.getByText('7201')).toBeInTheDocument();
    expect(screen.getByText(/12 bookings/)).toBeInTheDocument();
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
