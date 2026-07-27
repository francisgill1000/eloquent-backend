import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { VoiceAssistantFab } from './VoiceAssistantFab';

const navigate = vi.fn();
let pathname = '/settings';
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
  useLocation: () => ({ pathname }),
}));

// The FAB now depends on the shop + permissions: it must not offer a button
// that lands on a page the user cannot open, and it must stay out of the way
// when Home is already rendering the assistant inline.
let shop: { id: number; modules: Array<'bookings' | 'leads'> } = { id: 7, modules: ['leads'] };
let perms: string[] | null = null; // null = owner-equivalent, all allowed
vi.mock('@/context/ShopContext', () => ({
  useShop: () => ({
    shop,
    can: (p: string) => perms === null || perms.includes(p),
  }),
}));

describe('VoiceAssistantFab', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    pathname = '/settings';
    shop = { id: 7, modules: ['leads'] };
    perms = null;
  });

  it('navigates to the voice assistant page when tapped', () => {
    render(<VoiceAssistantFab />);
    fireEvent.click(screen.getByRole('button', { name: /assistant/i }));
    expect(navigate).toHaveBeenCalledWith('/ask');
  });

  it.each(['/ask', '/ask/5', '/booking/12'])('is hidden on %s (overlap-prone page)', (p) => {
    pathname = p;
    render(<VoiceAssistantFab />);
    expect(screen.queryByRole('button', { name: /assistant/i })).not.toBeInTheDocument();
  });

  it('shows on Home when Home is the Hunt dashboard', () => {
    // The mic is the only way to reach the assistant from the dashboard.
    pathname = '/';
    shop = { id: 7, modules: ['leads'] };
    perms = ['leads.view', 'assistant.use'];
    render(<VoiceAssistantFab />);
    expect(screen.getByRole('button', { name: /assistant/i })).toBeInTheDocument();
  });

  it('hides on Home when Home is already the assistant (bookings-only shop)', () => {
    pathname = '/';
    shop = { id: 7, modules: ['bookings'] };
    perms = ['assistant.use'];
    render(<VoiceAssistantFab />);
    expect(screen.queryByRole('button', { name: /assistant/i })).not.toBeInTheDocument();
  });

  it('hides everywhere without assistant.use, since /ask would reject them', () => {
    pathname = '/settings';
    perms = ['leads.view'];
    render(<VoiceAssistantFab />);
    expect(screen.queryByRole('button', { name: /assistant/i })).not.toBeInTheDocument();
  });
});
