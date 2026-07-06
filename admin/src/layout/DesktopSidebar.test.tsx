import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { DesktopSidebar } from './DesktopSidebar';
import { ShopContext } from '@/context/ShopContext';
import type { Shop } from '@/types';

function renderWith(modules: string[]) {
  const shop = { name: 'S', modules } as unknown as Shop;
  const ctx = { shop, logoutShop: () => {} } as never;
  render(
    <MemoryRouter>
      <ShopContext.Provider value={ctx}><DesktopSidebar /></ShopContext.Provider>
    </MemoryRouter>,
  );
}

describe('DesktopSidebar module gating', () => {
  it('bookings-only shop hides Business Hunt, shows Bookings', () => {
    renderWith(['bookings']);
    expect(screen.queryByText('Business Hunt')).toBeNull();
    expect(screen.getByText('Bookings')).toBeTruthy();
  });
  it('leads-only shop shows Business Hunt, hides Bookings', () => {
    renderWith(['leads']);
    expect(screen.getByText('Business Hunt')).toBeTruthy();
    expect(screen.queryByText('Bookings')).toBeNull();
  });
  it('always shows Home, Overview, Settings, Profile', () => {
    renderWith(['leads']);
    ['Home', 'Overview', 'Settings', 'Profile'].forEach((l) =>
      expect(screen.getByText(l)).toBeTruthy());
  });
});
