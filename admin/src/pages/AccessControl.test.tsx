import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import * as A from '@/lib/access';
import AccessControl from './AccessControl';

function setup() {
  return render(<MemoryRouter><ShopProvider><AccessControl /></ShopProvider></MemoryRouter>);
}

describe('AccessControl — staff users', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    vi.spyOn(A, 'listRoles').mockResolvedValue([]);
    vi.spyOn(A, 'listUsers').mockResolvedValue([]);
    vi.spyOn(A, 'listPermissionGroups').mockResolvedValue({});
  });

  it('creates a staff user with email and password', async () => {
    const spy = vi.spyOn(A, 'createUser').mockResolvedValue({
      id: 1, name: 'Sara', email: 'sara@example.com', is_active: true, role: null,
    });
    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /add/i }));
    await user.type(screen.getByLabelText(/name/i), 'Sara');
    await user.type(screen.getByLabelText(/^email$/i), 'sara@example.com');
    await user.type(screen.getByLabelText(/password/i), 'a-strong-pass');
    await user.click(screen.getByRole('button', { name: /^save$/i }));
    expect(spy).toHaveBeenCalledWith({
      name: 'Sara', email: 'sara@example.com', password: 'a-strong-pass', role_id: null, is_active: true,
    });
    expect(await screen.findByText('Sara')).toBeInTheDocument();
  });

  it('blocks creating a staff user without a password', async () => {
    const spy = vi.spyOn(A, 'createUser').mockResolvedValue({
      id: 1, name: 'Sara', email: 'sara@example.com', is_active: true, role: null,
    });
    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /add/i }));
    await user.type(screen.getByLabelText(/name/i), 'Sara');
    await user.type(screen.getByLabelText(/^email$/i), 'sara@example.com');
    await user.click(screen.getByRole('button', { name: /^save$/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/at least 8 characters/i)).toBeInTheDocument();
  });

  it('edits a staff user; leaving password blank keeps the existing one', async () => {
    vi.spyOn(A, 'listUsers').mockResolvedValue([
      { id: 5, name: 'Bob', email: 'bob@example.com', is_active: true, role: null },
    ]);
    const spy = vi.spyOn(A, 'updateUser').mockResolvedValue({
      id: 5, name: 'Bob', email: 'bob@new.com', is_active: true, role: null,
    });
    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByLabelText(/edit/i));
    const emailInput = screen.getByLabelText(/^email$/i);
    await user.clear(emailInput);
    await user.type(emailInput, 'bob@new.com');
    await user.click(screen.getByRole('button', { name: /^save$/i }));
    expect(spy).toHaveBeenCalledWith(5, {
      name: 'Bob', email: 'bob@new.com', password: undefined, role_id: null, is_active: true,
    });
  });
});

describe('AccessControl — role permission matrix', () => {
  // A Business Hunt shop's catalog: top-level Hunt menu + the pages that sit
  // under Settings. Mirrors PermissionCatalog::forShop() for modules ['leads'].
  const HUNT_GROUPS = {
    hunt: {
      label: 'Business Hunt', section: null,
      permissions: { 'leads.view': 'View leads', 'leads.search': 'Search businesses' },
    },
    assistant_config: {
      label: 'AI Assistant', section: 'Settings',
      permissions: { 'assistant.manage': 'Configure the assistant' },
    },
    access: {
      label: 'Users & Roles', section: 'Settings',
      permissions: { 'users.manage': 'Add, edit & delete users', 'roles.manage': 'Add, edit & delete roles' },
    },
  };

  beforeEach(() => {
    vi.restoreAllMocks();
    vi.spyOn(A, 'listUsers').mockResolvedValue([]);
    vi.spyOn(A, 'listRoles').mockResolvedValue([]);
    vi.spyOn(A, 'listPermissionGroups').mockResolvedValue(HUNT_GROUPS as never);
  });

  async function openRoleEditor() {
    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('tab', { name: /roles/i }));
    await user.click(await screen.findByRole('button', { name: /add/i }));
    return user;
  }

  it('grants an AI Assistant page without also granting Users & Roles', async () => {
    const spy = vi.spyOn(A, 'createRole').mockResolvedValue({
      id: 9, name: 'Assistant editor', permissions: ['assistant.manage'], is_owner: false,
    });
    const user = await openRoleEditor();

    await user.type(screen.getByPlaceholderText(/receptionist/i), 'Assistant editor');
    await user.click(screen.getByLabelText(/configure the assistant/i));
    await user.click(screen.getByRole('button', { name: /save role/i }));

    expect(spy).toHaveBeenCalledWith({ name: 'Assistant editor', permissions: ['assistant.manage'] });
    // The whole point: a Settings grant must not drag in user/role management,
    // which would let the grantee escalate to every permission.
    const sent = spy.mock.calls[0][0].permissions;
    expect(sent).not.toContain('users.manage');
    expect(sent).not.toContain('roles.manage');
  });

  it('renders each Settings page as its own group, not one aggregate toggle', async () => {
    await openRoleEditor();

    // Section header present…
    expect(screen.getByText('Settings')).toBeInTheDocument();
    // …with each page beneath it individually selectable.
    expect(screen.getByText('AI Assistant')).toBeInTheDocument();
    expect(screen.getByText('Users & Roles')).toBeInTheDocument();
    expect(screen.getByLabelText(/configure the assistant/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/add, edit & delete roles/i)).toBeInTheDocument();
    // The old collapsed control is gone.
    expect(screen.queryByText(/manage all settings pages/i)).not.toBeInTheDocument();
  });
});
