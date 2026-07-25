import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as api from '@/lib/wakeWordApi';
import WakeWordSettings from './WakeWordSettings';

const navigate = vi.fn();
vi.mock('react-router-dom', async (orig) => ({ ...(await orig() as object), useNavigate: () => navigate }));
vi.mock('@/lib/wakeWordApi');

const unset: api.WakeWordInfo = { phrase: null, effective_phrase: 'Northside Barbers', using_custom: false };

describe('WakeWordSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (api.getWakeWord as ReturnType<typeof vi.fn>).mockResolvedValue(unset);
    (api.saveWakeWord as ReturnType<typeof vi.fn>).mockResolvedValue(unset);
  });

  it('shows the effective phrase as the placeholder when nothing is saved', async () => {
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    expect(await screen.findByPlaceholderText('Northside Barbers')).toBeInTheDocument();
  });

  it('shows the saved phrase when one exists', async () => {
    (api.getWakeWord as ReturnType<typeof vi.fn>).mockResolvedValue(
      { phrase: 'Northside', effective_phrase: 'Northside', using_custom: true },
    );
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    expect(await screen.findByDisplayValue('Northside')).toBeInTheDocument();
  });

  it('saves the typed phrase', async () => {
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    const input = await screen.findByPlaceholderText('Northside Barbers');
    fireEvent.change(input, { target: { value: 'Northside' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(api.saveWakeWord).toHaveBeenCalledWith('Northside'));
  });

  it('saves null when the field is cleared', async () => {
    (api.getWakeWord as ReturnType<typeof vi.fn>).mockResolvedValue(
      { phrase: 'Northside', effective_phrase: 'Northside', using_custom: true },
    );
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    const input = await screen.findByDisplayValue('Northside');
    fireEvent.change(input, { target: { value: '  ' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(api.saveWakeWord).toHaveBeenCalledWith(null));
  });

  it('shows an error when saving fails', async () => {
    (api.saveWakeWord as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('nope'));
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    const input = await screen.findByPlaceholderText('Northside Barbers');
    fireEvent.change(input, { target: { value: 'Northside' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    expect(await screen.findByText(/could not save/i)).toBeInTheDocument();
  });

  it('hides the Test button when the browser has no speech recognition', async () => {
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    await screen.findByPlaceholderText('Northside Barbers');
    expect(screen.queryByRole('button', { name: /test it/i })).toBeNull();
  });
});
