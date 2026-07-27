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

/**
 * These tests cover MODULE gating, so by default the user is granted every
 * permission. Pass `perms` to narrow it and exercise PERMISSION gating instead.
 */
function renderWith(modules: string[], perms?: string[]) {
  const shop = { name: 'S', modules } as unknown as Shop;
  const ctx: Ctx = {
    shop,
    token: 'tok',
    loading: false,
    currentUser: null,
    permissions: perms ?? ['*'],
    can: (p: string) => perms === undefined || perms.includes(p),
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

  // Overview was folded into Home: on a Hunt shop, Home IS the dashboard that
  // Overview used to link to. Two entries landing on the same page reads as a
  // bug, so the duplicate was removed rather than re-pointed.
  it.each([['leads'], ['bookings']] as const)('no longer offers a separate Overview entry (%s shop)', (m) => {
    renderWith([m]);
    expect(screen.queryByText('Overview')).toBeNull();
    expect(screen.getByText('Home')).toBeTruthy();
  });
});

describe('DesktopSidebar permission gating', () => {
  // Home is the post-login landing screen, so it is grantable like any other
  // menu item rather than being permanently visible.
  it('shows Home to a user with home.view', () => {
    renderWith(['leads'], ['home.view']);
    expect(screen.getByText('Home')).toBeTruthy();
  });

  it('hides Home from a user without home.view', () => {
    renderWith(['leads'], ['leads.view']);
    expect(screen.queryByText('Home')).toBeNull();
    expect(screen.getByText('Business Hunt')).toBeTruthy();
  });
});
