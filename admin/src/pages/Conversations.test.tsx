import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { listConversations, deleteConversation } from '@/lib/assistant';
import Conversations from './Conversations';

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
}));
vi.mock('@/lib/assistant', () => ({
  listConversations: vi.fn().mockResolvedValue([]),
  renameConversation: vi.fn().mockResolvedValue(undefined),
  deleteConversation: vi.fn().mockResolvedValue(undefined),
}));

const asMock = (fn: unknown) => fn as unknown as ReturnType<typeof vi.fn>;
const rows = [
  { id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' },
  { id: 4, title: 'Revenue question', updated_at: '2026-07-06T10:00:00+00:00' },
];

describe('Conversations page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    asMock(listConversations).mockResolvedValue(rows);
  });

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

  it('filters the list by the search query', async () => {
    render(<Conversations />);
    await screen.findByText('Booking help');
    fireEvent.change(screen.getByPlaceholderText(/search chats/i), { target: { value: 'revenue' } });
    expect(screen.getByText('Revenue question')).toBeInTheDocument();
    expect(screen.queryByText('Booking help')).not.toBeInTheDocument();
  });

  it('shows a no-matches state when the search matches nothing', async () => {
    render(<Conversations />);
    await screen.findByText('Booking help');
    fireEvent.change(screen.getByPlaceholderText(/search chats/i), { target: { value: 'zzz' } });
    expect(screen.getByText(/no matches/i)).toBeInTheDocument();
  });

  it('has no New chat button', async () => {
    render(<Conversations />);
    await screen.findByText('Booking help');
    expect(screen.queryByRole('button', { name: /new chat/i })).toBeNull();
  });

  it('shows an empty state with a call to action when there are no chats', async () => {
    asMock(listConversations).mockResolvedValue([]);
    render(<Conversations />);
    expect(await screen.findByText(/no chats yet/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /ask something/i }));
    expect(navigate).toHaveBeenCalledWith('/ask');
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
