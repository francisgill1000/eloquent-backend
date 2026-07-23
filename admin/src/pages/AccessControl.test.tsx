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
