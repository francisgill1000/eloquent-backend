import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { getHistory, clearHistory, postText } from '@/lib/assistant';
import VoiceAssistant from './VoiceAssistant';

vi.mock('react-router-dom', () => ({ useNavigate: () => vi.fn() }));
vi.mock('@/lib/assistant', () => ({
  getHistory: vi.fn().mockResolvedValue([]),
  clearHistory: vi.fn().mockResolvedValue(undefined),
  postText: vi.fn().mockResolvedValue({ reply_text: 'You made 50 dirhams.', reply_audio_url: null }),
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
  beforeEach(() => {
    vi.clearAllMocks();
    (getHistory as unknown as ReturnType<typeof vi.fn>).mockResolvedValue([]);
    (postText as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ reply_text: 'You made 50 dirhams.', reply_audio_url: null });
    (clearHistory as unknown as ReturnType<typeof vi.fn>).mockResolvedValue(undefined);
  });

  it('renders the shop conversation loaded from the server on open', async () => {
    (getHistory as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce([
      { id: 1, role: 'assistant', content: 'welcome back', audio_url: null },
    ]);
    render(<VoiceAssistant />);
    expect(await screen.findByText('welcome back')).toBeInTheDocument();
  });

  it('shows the assistant text reply when there is no audio', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());
  });

  it('renders a replayable audio player when the reply has audio', async () => {
    (postText as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      reply_text: 'spoken answer',
      reply_audio_url: 'data:audio/ogg;base64,T2dnUw==',
    });
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: /play|pause/i })).toBeInTheDocument());
    expect(window.HTMLMediaElement.prototype.play).toHaveBeenCalled();
  });

  it('shows the transcript text alongside the audio player', async () => {
    (postText as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      reply_text: 'spoken answer',
      reply_audio_url: 'data:audio/ogg;base64,T2dnUw==',
    });
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: /play|pause/i })).toBeInTheDocument());
    expect(screen.getByText('spoken answer')).toBeInTheDocument();
  });

  it('clears the conversation via the server when the clear button is used', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /clear conversation/i }));
    await waitFor(() => expect(clearHistory).toHaveBeenCalled());
    expect(screen.queryByText('You made 50 dirhams.')).not.toBeInTheDocument();
  });
});
