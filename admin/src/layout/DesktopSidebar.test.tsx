import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import type { ComponentProps } from 'react';
import { DesktopSidebar } from './DesktopSidebar';
import { ShopContext } from '@/context/ShopContext';
import type { Shop } from '@/types';

/**
 * The real context value, derived from the provider so TypeScript checks this
 * fixture. It used to be cast `as never`, which hid the fact that the sidebar
 * had started calling `can()` for permission gating — the missing field only
 * surfaced at runtime as "can is not a function".
 */
type Ctx = NonNullable<ComponentProps<typeof ShopContext.Provider>['value']>;

/** These tests cover MODULE gating, so the user is granted every permission. */
function renderWith(modules: string[]) {
  const shop = { name: 'S', modules } as unknown as Shop;
  const ctx: Ctx = {
    shop,
    token: 'tok',
    loading: false,
    currentUser: null,
    permissions: ['*'],
    can: () => true,
    loginShop: () => {},
    setAccess: () => {},
    logoutShop: () => {},
  };
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
  it('always shows Home, Settings, Profile', () => {
    renderWith(['leads']);
    ['Home', 'Settings', 'Profile'].forEach((l) =>
      expect(screen.getByText(l)).toBeTruthy());
  });
});
