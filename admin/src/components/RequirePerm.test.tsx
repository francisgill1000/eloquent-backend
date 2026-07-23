import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import { RequirePerm } from './RequirePerm';

/**
 * Renders a tiny app with a guarded /leads and a reachable /profile, so a denied
 * user is observably redirected rather than shown the page.
 */
function setup(perms: string[] | null, modules: string[] = ['leads']) {
  storage.setJSON('shop_data', { id: 7, name: 'Acme', modules });
  storage.set('shop_token', 'tok');
  if (perms !== null) storage.setJSON('shop_permissions', perms);

  return render(
    <MemoryRouter initialEntries={['/leads']}>
      <ShopProvider>
        <Routes>
          <Route element={<RequirePerm perm="leads.view" />}>
            <Route path="/leads" element={<div>Hunt page</div>} />
          </Route>
          {/* Guarded like the real app, so the zero-permission fallback has
              nowhere left to send the user. */}
          <Route element={<RequirePerm perm="profile.view" />}>
            <Route path="/profile" element={<div>Profile page</div>} />
          </Route>
          <Route path="/ai-summary" element={<div>Summary page</div>} />
        </Routes>
      </ShopProvider>
    </MemoryRouter>,
  );
}

describe('RequirePerm', () => {
  beforeEach(() => { localStorage.clear(); });

  it('renders the page when the user holds the permission', async () => {
    setup(['leads.view']);
    expect(await screen.findByText('Hunt page')).toBeInTheDocument();
  });

  it('redirects away when the user lacks it — a hidden menu item is not enough', async () => {
    setup(['profile.view']);
    await waitFor(() => expect(screen.getByText('Profile page')).toBeInTheDocument());
    expect(screen.queryByText('Hunt page')).not.toBeInTheDocument();
  });

  it('sends a denied user to the first section they CAN see, not to Home', async () => {
    setup(['summary.view']);
    await waitFor(() => expect(screen.getByText('Summary page')).toBeInTheDocument());
  });

  it('shows a dead end instead of looping when the user can see nothing', async () => {
    setup([]);
    expect(await screen.findByText(/no access/i)).toBeInTheDocument();
    expect(screen.queryByText('Hunt page')).not.toBeInTheDocument();
  });

  it('fails open for a session whose permissions were never stored', async () => {
    // Pre-RBAC / pre-upgrade sessions have no stored permissions. Failing closed
    // would lock every already-logged-in user out of the whole app.
    setup(null);
    expect(await screen.findByText('Hunt page')).toBeInTheDocument();
  });
});
