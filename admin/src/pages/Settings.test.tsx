import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import { WHATSAPP_ENABLED } from '@/lib/features';
import Settings from './Settings';

function setup(shop: Record<string, unknown> = { id: 7, name: 'Acme' }) {
  storage.setJSON('shop_data', shop);
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Settings /></ShopProvider></MemoryRouter>);
}

describe('Settings', () => {
  beforeEach(() => { localStorage.clear(); });

  it('lists the settings options with their links', async () => {
    setup();
    expect(await screen.findByText('Settings')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /working hours/i })).toHaveAttribute('href', '/working-hours');
    expect(screen.getByRole('link', { name: /services/i })).toHaveAttribute('href', '/services');
    expect(screen.getByRole('link', { name: /staff/i })).toHaveAttribute('href', '/staff');

    // WhatsApp is behind a kill-switch: every WhatsApp surface, this link
    // included, disappears while it is off. Assert whichever way it is set so
    // the test stays honest when the flag is flipped back on.
    if (WHATSAPP_ENABLED) {
      expect(screen.getByRole('link', { name: /whatsapp/i })).toHaveAttribute('href', '/chats/setup');
    } else {
      expect(screen.queryByRole('link', { name: /whatsapp/i })).toBeNull();
    }
  });

  it('hides the master option for regular shops', async () => {
    setup();
    expect(await screen.findByText('Settings')).toBeInTheDocument();
    expect(screen.queryByText(/all businesses/i)).not.toBeInTheDocument();
  });

  it('shows the master option for master accounts', async () => {
    setup({ id: 1, name: 'Business Lens HQ', is_master: true });
    expect(await screen.findByRole('link', { name: /all businesses/i })).toHaveAttribute('href', '/master');
  });
});
