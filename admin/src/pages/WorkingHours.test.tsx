import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as shopsLib from '@/lib/shops';
import WorkingHours from './WorkingHours';

function setup() {
  storage.setJSON('shop_data', {
    id: 7, name: 'Acme',
    working_hours: [{ day_of_week: 1, start_time: '09:00', end_time: '17:00' }],
  });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><WorkingHours /></ShopProvider></MemoryRouter>);
}

describe('WorkingHours', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('saves only the open days as working_hours', async () => {
    vi.spyOn(shopsLib, 'getShop').mockResolvedValue({
      id: 7, name: 'Acme',
      working_hours: [{ day_of_week: 1, start_time: '09:00', end_time: '17:00' }],
    });
    const update = vi.spyOn(shopsLib, 'updateShop').mockResolvedValue({ id: 7, name: 'Acme' });
    setup();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /save working hours/i }));
    expect(update).toHaveBeenCalledWith(7, {
      working_hours: [{ day_of_week: 1, start_time: '09:00', end_time: '17:00', slot_duration: 30 }],
    });
    expect(await screen.findByText(/working hours updated/i)).toBeInTheDocument();
  });
});
