import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as shopsLib from '@/lib/shops';
import ScanApprove from './ScanApprove';

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(
    <MemoryRouter initialEntries={['/scan/abc-123']}>
      <ShopProvider>
        <Routes><Route path="/scan/:token" element={<ScanApprove />} /></Routes>
      </ShopProvider>
    </MemoryRouter>,
  );
}

describe('ScanApprove', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('approves the QR login token', async () => {
    const approve = vi.spyOn(shopsLib, 'approveQrLogin').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /approve login/i }));
    expect(approve).toHaveBeenCalledWith('abc-123');
    expect(await screen.findByText(/approved/i)).toBeInTheDocument();
  });

  it('prompts for login when not authenticated', () => {
    render(
      <MemoryRouter initialEntries={['/scan/abc-123']}>
        <ShopProvider>
          <Routes><Route path="/scan/:token" element={<ScanApprove />} /></Routes>
        </ShopProvider>
      </MemoryRouter>,
    );
    expect(screen.getByRole('link', { name: /log in/i })).toBeInTheDocument();
  });
});
