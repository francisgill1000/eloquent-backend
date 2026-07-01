import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as shopsLib from '@/lib/shops';
import Profile from './Profile';

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme', location: 'Dubai' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Profile /></ShopProvider></MemoryRouter>);
}

describe('Profile', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('saves edited profile fields', async () => {
    const update = vi.spyOn(shopsLib, 'updateShop').mockResolvedValue({ id: 7, name: 'Acme Spa' });
    setup();
    const user = userEvent.setup();
    const nameInput = screen.getByLabelText(/business name/i);
    await user.clear(nameInput);
    await user.type(nameInput, 'Acme Spa');
    await user.click(screen.getByRole('button', { name: /save profile/i }));
    expect(update).toHaveBeenCalledWith(7, expect.objectContaining({ name: 'Acme Spa' }));
  });

  it('logs out and clears the token', async () => {
    vi.stubGlobal('confirm', () => true);
    setup();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /sign out/i }));
    expect(localStorage.getItem('shop_token')).toBeNull();
  });
});
