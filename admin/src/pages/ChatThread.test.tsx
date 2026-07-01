import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as chatsLib from '@/lib/chats';
import ChatThread from './ChatThread';

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(
    <MemoryRouter initialEntries={['/chats/5']}>
      <ShopProvider>
        <Routes><Route path="/chats/:id" element={<ChatThread />} /></Routes>
      </ShopProvider>
    </MemoryRouter>,
  );
}

describe('ChatThread', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('renders messages and marks read', async () => {
    vi.spyOn(chatsLib, 'getWaContacts').mockResolvedValue({
      connected: true,
      data: [{ id: 5, wa_number: '971501112222', name: 'Ali', unread_count: 1 }],
    });
    vi.spyOn(chatsLib, 'getWaMessages').mockResolvedValue([
      { id: 1, direction: 'in', body: 'Hello there', created_at: '2026-06-06T10:00:00Z' },
      { id: 2, direction: 'out', body: 'Welcome!', created_at: '2026-06-06T10:01:00Z' },
    ]);
    const read = vi.spyOn(chatsLib, 'markWaRead').mockResolvedValue();

    setup();
    expect(await screen.findByText('Hello there')).toBeInTheDocument();
    expect(screen.getByText('Welcome!')).toBeInTheDocument();
    expect(screen.getByText('Ali')).toBeInTheDocument();
    expect(read).toHaveBeenCalledWith(5);
  });

  it('renders only the audio player for voice notes — no text', async () => {
    vi.spyOn(chatsLib, 'getWaContacts').mockResolvedValue({
      connected: true,
      data: [{ id: 5, wa_number: '971501112222', name: 'Ali', unread_count: 0 }],
    });
    vi.spyOn(chatsLib, 'getWaMessages').mockResolvedValue([
      { id: 1, direction: 'in', type: 'audio', body: '[audio message]', media_url: 'https://api.example/storage/wa-media/1/m1.ogg', created_at: '2026-06-07T10:00:00Z' },
      { id: 2, direction: 'in', type: 'audio', body: '🎤 book me for tomorrow', media_url: 'https://api.example/storage/wa-media/1/m2.ogg', created_at: '2026-06-07T10:01:00Z' },
    ]);
    vi.spyOn(chatsLib, 'markWaRead').mockResolvedValue();

    const { container } = setup();
    await screen.findByPlaceholderText(/type a reply/i);
    expect(container.querySelectorAll('audio')).toHaveLength(2);
    // audio bubbles hide both the raw placeholder and the transcript text
    expect(screen.queryByText('[audio message]')).not.toBeInTheDocument();
    expect(screen.queryByText(/book me for tomorrow/)).not.toBeInTheDocument();
  });

  it('sends a reply', async () => {
    vi.spyOn(chatsLib, 'getWaContacts').mockResolvedValue({
      connected: true,
      data: [{ id: 5, wa_number: '971501112222', name: 'Ali', unread_count: 0 }],
    });
    vi.spyOn(chatsLib, 'getWaMessages').mockResolvedValue([
      { id: 1, direction: 'in', body: 'Hello', created_at: '2026-06-06T10:00:00Z' },
    ]);
    vi.spyOn(chatsLib, 'markWaRead').mockResolvedValue();
    const send = vi.spyOn(chatsLib, 'sendWaMessage').mockResolvedValue({
      id: 2, direction: 'out', body: 'On our way', created_at: '2026-06-06T10:02:00Z',
    });

    setup();
    expect(await screen.findByText('Hello')).toBeInTheDocument();

    const user = userEvent.setup();
    await user.type(screen.getByPlaceholderText(/type a reply/i), 'On our way');
    await user.click(screen.getByRole('button', { name: /send/i }));

    expect(send).toHaveBeenCalledWith(5, 'On our way');
    expect(await screen.findByText('On our way')).toBeInTheDocument();
  });

  it('shows the API error when sending fails', async () => {
    vi.spyOn(chatsLib, 'getWaContacts').mockResolvedValue({
      connected: true,
      data: [{ id: 5, wa_number: '971501112222', name: 'Ali', unread_count: 0 }],
    });
    vi.spyOn(chatsLib, 'getWaMessages').mockResolvedValue([]);
    vi.spyOn(chatsLib, 'markWaRead').mockResolvedValue();
    vi.spyOn(chatsLib, 'sendWaMessage').mockRejectedValue({
      response: { data: { message: 'WhatsApp send failed: Token expired' } },
    });

    setup();
    expect(await screen.findByPlaceholderText(/type a reply/i)).toBeInTheDocument();

    const user = userEvent.setup();
    await user.type(screen.getByPlaceholderText(/type a reply/i), 'x');
    await user.click(screen.getByRole('button', { name: /send/i }));

    expect(await screen.findByText(/token expired/i)).toBeInTheDocument();
  });
});
