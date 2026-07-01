import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as chatsLib from '@/lib/chats';
import WhatsAppSetup from './WhatsAppSetup';

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><WhatsAppSetup /></ShopProvider></MemoryRouter>);
}

describe('WhatsAppSetup', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('connects with just number + phone number id (shared token)', async () => {
    vi.spyOn(chatsLib, 'getWaAccount').mockResolvedValue({ connected: false });
    const save = vi.spyOn(chatsLib, 'saveWaAccount').mockResolvedValue({
      connected: true, phone_number_id: 'pn_1', token_preview: 'shared',
    });

    setup();
    expect(await screen.findByText(/whatsapp setup/i)).toBeInTheDocument();

    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/whatsapp number/i), '+971500000001');
    await user.type(screen.getByLabelText(/phone number id/i), 'pn_1');
    await user.click(screen.getByRole('button', { name: /connect whatsapp/i }));

    expect(save).toHaveBeenCalledWith({
      phone_number: '+971500000001',
      phone_number_id: 'pn_1',
      token: undefined,
    });
    expect(await screen.findByText(/saved/i)).toBeInTheDocument();
  });

  it('requires phone number id', async () => {
    vi.spyOn(chatsLib, 'getWaAccount').mockResolvedValue({ connected: false });
    const save = vi.spyOn(chatsLib, 'saveWaAccount');

    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /connect whatsapp/i }));

    expect(await screen.findByText(/phone number id is required/i)).toBeInTheDocument();
    expect(save).not.toHaveBeenCalled();
  });

  it('allows an own-token override under advanced', async () => {
    vi.spyOn(chatsLib, 'getWaAccount').mockResolvedValue({ connected: false });
    const save = vi.spyOn(chatsLib, 'saveWaAccount').mockResolvedValue({
      connected: true, phone_number_id: 'pn_1', token_preview: '••••5678',
    });

    setup();
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText(/phone number id/i), 'pn_1');
    await user.click(screen.getByRole('button', { name: /advanced/i }));
    await user.type(screen.getByLabelText(/access token override/i), 'EAAown5678');
    await user.click(screen.getByRole('button', { name: /connect whatsapp/i }));

    expect(save).toHaveBeenCalledWith({
      phone_number: undefined,
      phone_number_id: 'pn_1',
      token: 'EAAown5678',
    });
  });

  it('offers skip when not connected', async () => {
    vi.spyOn(chatsLib, 'getWaAccount').mockResolvedValue({ connected: false });
    setup();
    expect(await screen.findByRole('button', { name: /skip for now/i })).toBeInTheDocument();
  });
});
