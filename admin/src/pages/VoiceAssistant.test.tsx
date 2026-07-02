import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { postText } from '@/lib/assistant';
import VoiceAssistant from './VoiceAssistant';

vi.mock('react-router-dom', () => ({ useNavigate: () => vi.fn() }));
vi.mock('@/lib/assistant', () => ({
  postText: vi.fn().mockResolvedValue({
    transcript: 'hi',
    reply_text: 'You made 50 dirhams.',
    reply_audio_url: null,
    history: [],
  }),
  postVoice: vi.fn(),
}));
vi.mock('@/hooks/useRecorder', () => ({
  useRecorder: () => ({ recording: false, start: vi.fn(), stop: vi.fn(), supported: true }),
}));

// jsdom does not implement HTMLMediaElement.play; stub it so AudioBubble's
// auto-play does not throw.
beforeAll(() => {
  window.HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined);
  window.HTMLMediaElement.prototype.pause = vi.fn();
});

describe('VoiceAssistant page', () => {
  beforeEach(() => localStorage.clear());

  it('shows the assistant text reply when there is no audio', async () => {
    render(<VoiceAssistant />);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());
  });

  it('renders a replayable audio player when the reply has audio', async () => {
    (postText as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      transcript: 'hi',
      reply_text: 'spoken answer',
      reply_audio_url: 'data:audio/ogg;base64,T2dnUw==',
      history: [],
    });
    render(<VoiceAssistant />);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: /play|pause/i })).toBeInTheDocument());
    expect(window.HTMLMediaElement.prototype.play).toHaveBeenCalled();
  });

  it('shows the transcript text alongside the audio player', async () => {
    (postText as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      transcript: 'hi',
      reply_text: 'spoken answer',
      reply_audio_url: 'data:audio/ogg;base64,T2dnUw==',
      history: [],
    });
    render(<VoiceAssistant />);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    // Both the replayable player and its readable transcript are present.
    await waitFor(() => expect(screen.getByRole('button', { name: /play|pause/i })).toBeInTheDocument());
    expect(screen.getByText('spoken answer')).toBeInTheDocument();
  });

  it('restores the conversation from storage on reload', async () => {
    const { unmount } = render(<VoiceAssistant />);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());
    unmount();

    // A fresh mount (simulating a reload) rehydrates the prior conversation.
    render(<VoiceAssistant />);
    expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument();
  });

  it('clears the stored conversation when the clear button is used', async () => {
    render(<VoiceAssistant />);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /clear conversation/i }));
    expect(screen.queryByText('You made 50 dirhams.')).not.toBeInTheDocument();
    expect(localStorage.getItem('va-conversation:anon')).toBe('[]');
  });

  it('keeps each shop\'s conversation separate (no cross-shop leak)', async () => {
    // Shop A logs in and chats.
    localStorage.setItem('shop_data', JSON.stringify({ id: 1, name: 'Shop A' }));
    const a = render(<VoiceAssistant />);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());
    a.unmount();

    // A different shop logs in on the same device — it must NOT see Shop A's chat.
    localStorage.setItem('shop_data', JSON.stringify({ id: 2, name: 'Shop B' }));
    render(<VoiceAssistant />);
    expect(screen.queryByText('You made 50 dirhams.')).not.toBeInTheDocument();
  });
});
