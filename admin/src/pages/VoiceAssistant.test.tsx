import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import {
  getConversation, listConversations, renameConversation, deleteConversation, postText,
} from '@/lib/assistant';
import VoiceAssistant from './VoiceAssistant';

const navigate = vi.fn();
let params: { conversationId?: string } = {};
// Shop context: default to a normal (non-master) shop; individual tests flip
// `shopValue.is_master` to exercise the master-redirect guard.
let shopValue: { is_master?: boolean } | null = { is_master: false };
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
  useParams: () => params,
  Navigate: ({ to }: { to: string }) => <div>REDIRECT:{to}</div>,
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
    shopValue = { is_master: false };
    asMock(getConversation).mockResolvedValue([]);
    asMock(listConversations).mockResolvedValue({ conversations: [], has_more: false });
    asMock(postText).mockResolvedValue({ conversation_id: 9, title: 'how much', reply_text: 'You made 50 dirhams.', reply_audio_url: null });
  });

  it('starts a new empty thread on /ask (no history fetched)', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    expect(getConversation).not.toHaveBeenCalled();
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

  it('opens the history drawer and lists threads', async () => {
    asMock(listConversations).mockResolvedValue({ conversations: [{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }], has_more: false });
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.click(screen.getByRole('button', { name: /history/i }));
    expect(await screen.findByText('Booking help')).toBeInTheDocument();
  });

  it('navigates to a thread when picked from the drawer', async () => {
    asMock(listConversations).mockResolvedValue({ conversations: [{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }], has_more: false });
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.click(screen.getByRole('button', { name: /history/i }));
    fireEvent.click(await screen.findByText('Booking help'));
    expect(navigate).toHaveBeenCalledWith('/ask/3');
  });

  it('deletes a thread from the drawer', async () => {
    asMock(listConversations).mockResolvedValue({ conversations: [{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }], has_more: false });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.click(screen.getByRole('button', { name: /history/i }));
    await screen.findByText('Booking help');
    fireEvent.click(screen.getByRole('button', { name: /delete thread/i }));
    await waitFor(() => expect(deleteConversation).toHaveBeenCalledWith(3));
  });

  it('renames a thread from the drawer', async () => {
    asMock(listConversations).mockResolvedValue({ conversations: [{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }], has_more: false });
    vi.spyOn(window, 'prompt').mockReturnValue('New name');
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.click(screen.getByRole('button', { name: /history/i }));
    await screen.findByText('Booking help');
    fireEvent.click(screen.getByRole('button', { name: /rename thread/i }));
    await waitFor(() => expect(renameConversation).toHaveBeenCalledWith(3, 'New name'));
  });
});
