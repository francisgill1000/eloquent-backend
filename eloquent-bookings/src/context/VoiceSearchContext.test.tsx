import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { VoiceSearchProvider, useVoiceSearch } from './VoiceSearchContext';
import * as aiLib from '@/lib/ai';

const navigateSpy = vi.fn();
vi.mock('react-router-dom', async (orig) => ({
  ...(await orig<typeof import('react-router-dom')>()),
  useNavigate: () => navigateSpy,
}));

function Harness() {
  const { messages, send } = useVoiceSearch();
  return (
    <div>
      <button onClick={() => send('hello')}>go</button>
      <ul>{messages.map((m) => <li key={m.id} data-role={m.role} data-auth={m.auth?.mode ?? ''}>{m.text}</li>)}</ul>
    </div>
  );
}

function renderHarness() {
  return render(<MemoryRouter><VoiceSearchProvider><Harness /></VoiceSearchProvider></MemoryRouter>);
}

describe('VoiceSearchContext.send', () => {
  beforeEach(() => vi.clearAllMocks());

  it('sends the thread history including the new user turn', async () => {
    const spy = vi.spyOn(aiLib, 'aiSearch').mockResolvedValue({ reply: 'hi there', shops: [], action: null });
    renderHarness();

    await act(async () => { screen.getByText('go').click(); });

    expect(spy).toHaveBeenCalledWith([{ role: 'user', content: 'hello' }], undefined);
    expect(screen.getByText('hi there')).toBeInTheDocument();
  });

  it('executes a navigate action via the router', async () => {
    vi.spyOn(aiLib, 'aiSearch').mockResolvedValue({ reply: 'opening', shops: [], action: { type: 'navigate', route: '/bookings' } });
    renderHarness();

    await act(async () => { screen.getByText('go').click(); });

    expect(navigateSpy).toHaveBeenCalledWith('/bookings');
  });

  it('attaches an auth payload for a login action', async () => {
    vi.spyOn(aiLib, 'aiSearch').mockResolvedValue({ reply: 'one sec', shops: [], action: { type: 'login', fields: { phone: '0501234567' } } });
    renderHarness();

    await act(async () => { screen.getByText('go').click(); });

    const aiTurn = screen.getByText('one sec').closest('li');
    expect(aiTurn?.getAttribute('data-auth')).toBe('login');
  });
});
