import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import { Landing } from './Landing';

// Home composes two heavy real components. Stub both: what matters here is
// WHICH one Home picks for a given shop + permission set, not what they render.
vi.mock('@/components/HuntDashboard', () => ({
  HuntDashboard: () => <div>HUNT DASHBOARD</div>,
}));
vi.mock('@/pages/VoiceAssistant', () => ({
  default: () => <div>ASK ASSISTANT</div>,
}));

type Modules = Array<'bookings' | 'leads'>;

function setup(modules: Modules, perms?: string[]) {
  storage.setJSON('shop_data', { id: 7, name: 'Acme', modules });
  storage.set('shop_token', 'tok');
  // Omitting perms leaves permissions null — owner-equivalent, all allowed.
  if (perms) storage.setJSON('shop_permissions', perms);
  return render(<MemoryRouter><ShopProvider><Landing /></ShopProvider></MemoryRouter>);
}

describe('Landing (Home)', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.clearAllMocks();
  });

  it('shows the Hunt dashboard to a Hunt user with leads.view', () => {
    setup(['leads'], ['leads.view']);
    expect(screen.getByText('HUNT DASHBOARD')).toBeInTheDocument();
    expect(screen.queryByText('ASK ASSISTANT')).toBeNull();
  });

  it('shows the dashboard to a lead agent too (the dashboard scopes itself)', () => {
    // No leads.view_all — still their Home; the AI card inside is what hides.
    setup(['leads'], ['leads.view']);
    expect(screen.getByText('HUNT DASHBOARD')).toBeInTheDocument();
  });

  it('shows the Ask assistant on a bookings-only shop', () => {
    setup(['bookings'], ['assistant.use']);
    expect(screen.getByText('ASK ASSISTANT')).toBeInTheDocument();
    expect(screen.queryByText('HUNT DASHBOARD')).toBeNull();
  });

  it('shows the assistant to a Hunt user who has assistant.use but not leads.view', () => {
    setup(['leads'], ['assistant.use']);
    expect(screen.getByText('ASK ASSISTANT')).toBeInTheDocument();
    expect(screen.queryByText('HUNT DASHBOARD')).toBeNull();
  });

  it('shows an empty state when the user has neither section', () => {
    setup(['leads'], ['profile.view']);
    expect(screen.getByText(/no access/i)).toBeInTheDocument();
    expect(screen.queryByText('HUNT DASHBOARD')).toBeNull();
    expect(screen.queryByText('ASK ASSISTANT')).toBeNull();
  });

  it('gives an owner on a Hunt shop the dashboard', () => {
    setup(['leads']);
    expect(screen.getByText('HUNT DASHBOARD')).toBeInTheDocument();
  });

  it('gives an owner on a bookings shop the assistant', () => {
    setup(['bookings']);
    expect(screen.getByText('ASK ASSISTANT')).toBeInTheDocument();
  });
});
