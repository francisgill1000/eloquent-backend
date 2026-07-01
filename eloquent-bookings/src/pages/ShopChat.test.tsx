import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as chatLib from '@/lib/chat';
import ShopChat from './ShopChat';

// ShopChat fetches the shop (logo/name) and posts replies to /tts for voice.
// Mock the api client so tests never hit the real backend.
vi.mock('@/lib/api', () => ({
  default: {
    get: () => Promise.resolve({ data: { data: { name: 'Glow Salon', logo: '/logo.png' } } }),
    post: () => Promise.reject(new Error('no tts in test')),
  },
}));

function setup() {
  return render(
    <MemoryRouter initialEntries={[{ pathname: '/shop/5/chat', state: { shopName: 'Glow Salon' } }]}>
      <Routes><Route path="/shop/:id/chat" element={<ShopChat />} /></Routes>
    </MemoryRouter>,
  );
}

describe('ShopChat', () => {
  beforeEach(() => { vi.restoreAllMocks(); });

  it('renders the thread with my messages on the out side', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([
      { id: 1, direction: 'in', body: 'Do you have slots today?', created_at: '2026-06-12T10:00:00Z' },
      { id: 2, direction: 'out', body: 'Yes! 3pm is free 😊', created_at: '2026-06-12T10:00:05Z' },
    ]);

    setup();
    expect(await screen.findByText('Do you have slots today?')).toBeInTheDocument();
    expect(screen.getByText('Yes! 3pm is free 😊')).toBeInTheDocument();
    expect(screen.getByText('Glow Salon')).toBeInTheDocument();
    // direction 'in' = sent by me → rendered as an outgoing bubble
    expect(screen.getByText('Do you have slots today?').closest('.c-bubble')).toHaveClass('out');
    expect(screen.getByText('Yes! 3pm is free 😊').closest('.c-bubble')).toHaveClass('in');
  });

  it('shows the empty hint when there is no history', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([]);

    setup();
    expect(await screen.findByText(/say hi/i)).toBeInTheDocument();
  });

  it('sends a message', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([]);
    const send = vi.spyOn(chatLib, 'sendChatMessage').mockResolvedValue({
      id: 9, direction: 'in', body: 'How much is a haircut?', created_at: '2026-06-12T10:02:00Z',
    });

    setup();
    const user = userEvent.setup();
    await user.type(await screen.findByPlaceholderText(/type a message/i), 'How much is a haircut?');
    await user.click(screen.getByRole('button', { name: /send/i }));

    expect(send).toHaveBeenCalledWith(5, 'How much is a haircut?');
    expect(await screen.findByText('How much is a haircut?')).toBeInTheDocument();
  });

  it('shows an error when sending fails', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([]);
    vi.spyOn(chatLib, 'sendChatMessage').mockRejectedValue(new Error('network'));

    setup();
    const user = userEvent.setup();
    await user.type(await screen.findByPlaceholderText(/type a message/i), 'x');
    await user.click(screen.getByRole('button', { name: /send/i }));

    expect(await screen.findByText(/could not send/i)).toBeInTheDocument();
  });

  it('shows the AI core orb using the shop logo, idle on load', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([]);

    setup();
    await screen.findByText(/say hi/i);
    const orb = screen.getByTestId('ai-core');
    // logo arrives from the shop fetch
    await waitFor(() => expect(orb.querySelector('img')).toHaveAttribute('src', '/logo.png'));
    expect(orb).toHaveClass('state-idle');
    expect(screen.getByText('Glow Salon')).toBeInTheDocument();
  });

  it('renders an AI voice reply as a player with no transcript', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([
      { id: 3, direction: 'out', type: 'audio', media_url: 'https://x/r.mp3', body: '🔊 Sure, 3pm works!', created_at: '2026-06-12T10:00:00Z' },
    ]);

    setup();
    expect(await screen.findByRole('button', { name: /play voice message/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /transcript/i })).toBeNull();
    expect(screen.queryByText(/sure, 3pm works/i)).toBeNull();
  });

  it('renders a customer voice note as a player with no transcript', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([
      { id: 4, direction: 'in', type: 'audio', media_url: 'https://x/m.mp3', body: '🎤 i need a haircut', created_at: '2026-06-12T10:00:00Z' },
    ]);

    setup();
    expect(await screen.findByRole('button', { name: /play voice message/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /transcript/i })).toBeNull();
  });

  it('moves the orb to thinking after sending a message', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([]);
    vi.spyOn(chatLib, 'sendChatMessage').mockResolvedValue({
      id: 9, direction: 'in', body: 'How much is a haircut?', created_at: '2026-06-12T10:02:00Z',
    });

    setup();
    const user = userEvent.setup();
    await user.type(await screen.findByPlaceholderText(/type a message/i), 'How much is a haircut?');
    await user.click(screen.getByRole('button', { name: /send/i }));

    expect(await screen.findByText('How much is a haircut?')).toBeInTheDocument();
    expect(screen.getByTestId('ai-core')).toHaveClass('state-thinking');
  });

  it('has a voice toggle in the header, on by default, that mutes when tapped', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([]);

    setup();
    await screen.findByText(/say hi/i);
    // On by default → the action is to mute.
    const toggle = screen.getByRole('button', { name: /mute voice/i });
    expect(toggle).toBeInTheDocument();

    const user = userEvent.setup();
    await user.click(toggle);
    // Now muted → the action becomes unmute.
    expect(screen.getByRole('button', { name: /unmute voice/i })).toBeInTheDocument();
  });
});
