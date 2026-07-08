import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { getConversation, postText } from '@/lib/assistant';
import * as sim from '@/lib/simulation';
import VoiceAssistant from './VoiceAssistant';

vi.mock('@/lib/simulation');

const navigate = vi.fn();
let params: { conversationId?: string } = {};
// Query params for the current route; individual tests set this to exercise
// `?sim=1` sim mode. Defaults to none so existing tests are unaffected.
let searchParams = new URLSearchParams();
// Shop context: default to a normal (non-master) shop; individual tests flip
// `shopValue.is_master` to exercise the master-redirect guard.
let shopValue: { is_master?: boolean } | null = { is_master: false };
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
  useParams: () => params,
  useSearchParams: () => [searchParams, vi.fn()],
  Navigate: ({ to }: { to: string }) => <div>REDIRECT:{to}</div>,
  MemoryRouter: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  Routes: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  Route: ({ element }: { element: React.ReactNode }) => <>{element}</>,
}));
vi.mock('@/context/ShopContext', () => ({
  useShop: () => ({ shop: shopValue }),
}));
vi.mock('@/lib/assistant', () => ({
  getConversation: vi.fn().mockResolvedValue([]),
  listConversations: vi.fn().mockResolvedValue({ conversations: [], has_more: false }),
  renameConversation: vi.fn().mockResolvedValue(undefined),
  deleteConversation: vi.fn().mockResolvedValue(undefined),
  postText: vi.fn().mockResolvedValue({ conversation_id: 9, title: 'how much', reply_text: 'You made 50 dirhams.', reply_audio_url: null }),
  postVoice: vi.fn(),
}));
vi.mock('@/hooks/useRecorder', () => ({
  useRecorder: () => ({ recording: false, start: vi.fn(), stop: vi.fn(), supported: true }),
}));

const asMock = (fn: unknown) => fn as unknown as ReturnType<typeof vi.fn>;

beforeAll(() => {
  window.HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined);
  window.HTMLMediaElement.prototype.pause = vi.fn();
});

describe('VoiceAssistant page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    params = {};
    searchParams = new URLSearchParams();
    shopValue = { is_master: false };
    asMock(getConversation).mockResolvedValue([]);
    asMock(postText).mockResolvedValue({ conversation_id: 9, title: 'how much', reply_text: 'You made 50 dirhams.', reply_audio_url: null });
  });

  it('starts a new empty thread on /ask (no history fetched)', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    expect(getConversation).not.toHaveBeenCalled();
  });

  it('shows the new-chat prompt on a fresh chat', async () => {
    render(<VoiceAssistant />);
    expect(await screen.findByText(/tap the mic/i)).toBeInTheDocument();
  });

  it('hides the new-chat prompt when opening an existing chat', async () => {
    params = { conversationId: '7' };
    asMock(getConversation).mockResolvedValueOnce([]); // existing thread, no messages loaded
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    expect(screen.queryByText(/tap the mic/i)).not.toBeInTheDocument();
  });

  it('redirects a master account to /master instead of showing the assistant', async () => {
    shopValue = { is_master: true };
    render(<VoiceAssistant />);
    expect(await screen.findByText('REDIRECT:/master')).toBeInTheDocument();
    expect(screen.queryByPlaceholderText(/type/i)).not.toBeInTheDocument();
  });

  it('nudges to start a new chat once a thread gets long', async () => {
    params = { conversationId: '5' };
    const many = Array.from({ length: 40 }, (_, i) => ({ id: i + 1, role: i % 2 === 0 ? 'user' : 'assistant', content: `m${i}`, audio_url: null }));
    asMock(getConversation).mockResolvedValueOnce(many);
    render(<VoiceAssistant />);
    const nudge = await screen.findByText(/getting long/i);
    expect(nudge).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /start new chat/i }));
    expect(navigate).toHaveBeenCalledWith('/ask');
  });

  it('shows no long-thread nudge for a short conversation', async () => {
    params = { conversationId: '5' };
    asMock(getConversation).mockResolvedValueOnce([{ id: 1, role: 'assistant', content: 'hi', audio_url: null }]);
    render(<VoiceAssistant />);
    await screen.findByText('hi');
    expect(screen.queryByText(/getting long/i)).not.toBeInTheDocument();
  });

  it('loads an existing thread from the route param', async () => {
    params = { conversationId: '5' };
    asMock(getConversation).mockResolvedValueOnce([{ id: 1, role: 'assistant', content: 'welcome back', audio_url: null }]);
    render(<VoiceAssistant />);
    expect(await screen.findByText('welcome back')).toBeInTheDocument();
    expect(getConversation).toHaveBeenCalledWith(5);
  });

  it('adopts the returned conversation id after the first send', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());
    expect(postText).toHaveBeenCalledWith('how much', undefined);
    expect(navigate).toHaveBeenCalledWith('/ask/9', { replace: true });
  });

  it('navigates to the booking when the reply carries a navigate action', async () => {
    asMock(postText).mockResolvedValue({ conversation_id: 9, title: 't', reply_text: 'Opening it.', reply_audio_url: null, action: { type: 'navigate', route: '/booking/7' } });
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'yes' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('Opening it.')).toBeInTheDocument());
    expect(navigate).toHaveBeenCalledWith('/booking/7');
  });

  it('does not navigate when the reply has no action', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());
    expect(navigate).not.toHaveBeenCalledWith(expect.stringContaining('/booking/'));
  });

  it('sim mode plays the script and ends on the booking preview', async () => {
    // jsdom has no real audio — make play() resolve and let us fire `ended`.
    window.HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined);
    window.HTMLMediaElement.prototype.pause = vi.fn();
    searchParams = new URLSearchParams('sim=1');

    const script = {
      turns: [{ who: 'owner', text: 'Book Sarah.' }, { who: 'assistant', text: 'Done.' }],
      booking: { customer_name: 'Sarah', customer_phone: '055 010 2030', service: 'Hair Cut', price: '150.00', date: '2026-07-09', start_time: '09:30', end_time: '10:00', staff_name: 'Aisha' },
      voices: { owner: 'shimmer', assistant: 'nova' }, thinking_ms: 0,
    };
    (sim.getSimulation as ReturnType<typeof vi.fn>).mockResolvedValue(script);
    (sim.speak as ReturnType<typeof vi.fn>).mockResolvedValue('blob:audio');

    render(
      <MemoryRouter initialEntries={['/ask?sim=1']}>
        <Routes><Route path="/ask" element={<VoiceAssistant />} /></Routes>
      </MemoryRouter>,
    );

    // Tap the Start overlay (user gesture for autoplay).
    fireEvent.click(await screen.findByRole('button', { name: /start/i }));

    // First line requested in the owner voice.
    await waitFor(() => expect(sim.speak).toHaveBeenCalledWith('Book Sarah.', 'shimmer'));
  });
});
