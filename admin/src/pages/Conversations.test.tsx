import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { listConversations, deleteConversation } from '@/lib/assistant';
import Conversations from './Conversations';

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
}));
vi.mock('@/lib/assistant', () => ({
  listConversations: vi.fn(),
  renameConversation: vi.fn().mockResolvedValue(undefined),
  deleteConversation: vi.fn().mockResolvedValue(undefined),
}));

const asMock = (fn: unknown) => fn as unknown as ReturnType<typeof vi.fn>;
type Row = { id: number; title: string; updated_at: string };

// The dataset the fake API "serves"; each test sets it, and the mock filters by
// `q` and paginates 20-per-page exactly like the real server does.
let dataset: Row[] = [];
const PER = 20;

beforeEach(() => {
  vi.clearAllMocks();
  dataset = [
    { id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' },
    { id: 4, title: 'Revenue question', updated_at: '2026-07-06T10:00:00+00:00' },
  ];
  asMock(listConversations).mockImplementation(async ({ page = 1, q = '' }: { page?: number; q?: string } = {}) => {
    const needle = q.trim().toLowerCase();
    const filtered = needle ? dataset.filter((c) => c.title.toLowerCase().includes(needle)) : dataset;
    const start = (page - 1) * PER;
    return { conversations: filtered.slice(start, start + PER), has_more: start + PER < filtered.length };
  });
});

describe('Conversations page', () => {
  it('lists the shop’s past assistant conversations', async () => {
    render(<Conversations />);
    expect(await screen.findByText('Booking help')).toBeInTheDocument();
    expect(screen.getByText('Revenue question')).toBeInTheDocument();
  });

  it('opens a conversation at /ask/:id when tapped', async () => {
    render(<Conversations />);
    fireEvent.click(await screen.findByText('Booking help'));
    expect(navigate).toHaveBeenCalledWith('/ask/3');
  });

  it('filters via server-side search (calls the API with the query)', async () => {
    render(<Conversations />);
    await screen.findByText('Booking help');
    fireEvent.change(screen.getByPlaceholderText(/search chats/i), { target: { value: 'revenue' } });
    // Debounced request → Revenue stays, Booking is gone once results arrive.
    expect(await screen.findByText('Revenue question')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByText('Booking help')).not.toBeInTheDocument());
    expect(listConversations).toHaveBeenCalledWith({ page: 1, q: 'revenue' });
  });

  it('shows a no-matches state when the search matches nothing', async () => {
    render(<Conversations />);
    await screen.findByText('Booking help');
    fireEvent.change(screen.getByPlaceholderText(/search chats/i), { target: { value: 'zzz' } });
    expect(await screen.findByText(/no matches/i)).toBeInTheDocument();
  });

  it('loads the next page when "Load more" is tapped', async () => {
    dataset = Array.from({ length: 25 }, (_, i) => ({ id: i + 1, title: `Chat ${i + 1}`, updated_at: '2026-07-07T10:00:00+00:00' }));
    render(<Conversations />);
    expect(await screen.findByText('Chat 1')).toBeInTheDocument();
    // Page 1 stops at 20; the 21st is not shown until we load more.
    expect(screen.queryByText('Chat 21')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /load more/i }));
    expect(await screen.findByText('Chat 25')).toBeInTheDocument();
    expect(listConversations).toHaveBeenCalledWith({ page: 2, q: '' });
    // No more pages → the button is gone.
    expect(screen.queryByRole('button', { name: /load more/i })).toBeNull();
  });

  it('shows an empty state with a call to action when there are no chats', async () => {
    dataset = [];
    render(<Conversations />);
    expect(await screen.findByText(/no chats yet/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /ask something/i }));
    expect(navigate).toHaveBeenCalledWith('/ask');
    // With no chats at all, the search box is hidden.
    expect(screen.queryByPlaceholderText(/search chats/i)).toBeNull();
  });

  it('has no New chat button', async () => {
    render(<Conversations />);
    await screen.findByText('Booking help');
    expect(screen.queryByRole('button', { name: /new chat/i })).toBeNull();
  });

  it('deletes a conversation after confirmation', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<Conversations />);
    await screen.findByText('Booking help');
    fireEvent.click(screen.getAllByRole('button', { name: /delete chat/i })[0]);
    await waitFor(() => expect(deleteConversation).toHaveBeenCalledWith(3));
    await waitFor(() => expect(screen.queryByText('Booking help')).not.toBeInTheDocument());
  });
});
