import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as shopsLib from '@/lib/shops';
import MasterShopCreate from './MasterShopCreate';

function setup() {
  return render(<MemoryRouter><MasterShopCreate /></MemoryRouter>);
}

describe('MasterShopCreate', () => {
  beforeEach(() => { vi.restoreAllMocks(); });

  it('creates a business and reveals its credentials', async () => {
    vi.spyOn(shopsLib, 'getServiceCategories').mockResolvedValue([{ id: 9, name: 'Salon' }]);
    const create = vi.spyOn(shopsLib, 'registerShop').mockResolvedValue({
      shop: { id: 31, name: 'New Salon', shop_code: '555666', pin: '1234' },
      token: 'tok-x',
    });

    setup();
    const user = (await import('@testing-library/user-event')).default.setup();
    await user.type(await screen.findByLabelText(/business name/i), 'New Salon');
    await user.type(screen.getByLabelText(/phone number/i), '0501239876');
    await user.selectOptions(screen.getByLabelText(/service category/i), '9');
    await user.click(screen.getByRole('button', { name: /create business/i }));

    expect(create).toHaveBeenCalledWith({
      name: 'New Salon', phone: '0501239876', category_id: 9, is_verified: true,
    });
    expect(await screen.findByText('555666')).toBeInTheDocument();
    expect(screen.getByText('1234')).toBeInTheDocument();
  });

  it('validates required fields before calling the API', async () => {
    vi.spyOn(shopsLib, 'getServiceCategories').mockResolvedValue([{ id: 9, name: 'Salon' }]);
    const create = vi.spyOn(shopsLib, 'registerShop');

    setup();
    const user = (await import('@testing-library/user-event')).default.setup();
    await user.click(await screen.findByRole('button', { name: /create business/i }));

    expect(create).not.toHaveBeenCalled();
    expect(screen.getByText(/business name is required/i)).toBeInTheDocument();
  });
});
