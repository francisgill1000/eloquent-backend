import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as shopsLib from '@/lib/shops';
import Staff from './Staff';

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Staff /></ShopProvider></MemoryRouter>);
}

describe('Staff', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('adds a staff member', async () => {
    vi.spyOn(shopsLib, 'getStaff').mockResolvedValue([{ id: 1, name: 'Joe', is_active: true }]);
    const add = vi.spyOn(shopsLib, 'addStaff').mockResolvedValue({ id: 2, name: 'Mia', is_active: true });

    setup();
    expect(await screen.findByText('Joe')).toBeInTheDocument();

    const user = userEvent.setup();
    await user.type(screen.getByPlaceholderText(/new staff name/i), 'Mia');
    await user.click(screen.getByRole('button', { name: /^add$/i }));
    expect(add).toHaveBeenCalledWith(7, 'Mia');
    expect(await screen.findByText('Mia')).toBeInTheDocument();
  });
});
