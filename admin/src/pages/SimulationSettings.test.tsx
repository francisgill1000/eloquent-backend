import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as sim from '@/lib/simulation';
import SimulationSettings from './SimulationSettings';

const navigate = vi.fn();
vi.mock('react-router-dom', async (orig) => ({ ...(await orig() as object), useNavigate: () => navigate }));
vi.mock('@/lib/simulation');

const script: sim.SimScript = {
  turns: [{ who: 'owner', text: 'Book Sarah for a haircut.' }, { who: 'assistant', text: 'Done.' }],
  booking: { customer_name: 'Sarah', customer_phone: '055 010 2030', service: 'Hair Cut', price: '150.00', date: '2026-07-09', start_time: '09:30', end_time: '10:00', staff_name: 'Aisha' },
  voices: { owner: 'shimmer', assistant: 'nova' },
  thinking_ms: 1400,
};

describe('SimulationSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (sim.getSimulation as ReturnType<typeof vi.fn>).mockResolvedValue(script);
    (sim.saveSimulation as ReturnType<typeof vi.fn>).mockResolvedValue(script);
  });

  it('loads and shows the script turns', async () => {
    render(<MemoryRouter><SimulationSettings /></MemoryRouter>);
    expect(await screen.findByDisplayValue('Book Sarah for a haircut.')).toBeInTheDocument();
  });

  it('saves on Save', async () => {
    render(<MemoryRouter><SimulationSettings /></MemoryRouter>);
    await screen.findByDisplayValue('Book Sarah for a haircut.');
    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(sim.saveSimulation).toHaveBeenCalled());
  });

  it('Play navigates to the Ask screen in sim mode', async () => {
    render(<MemoryRouter><SimulationSettings /></MemoryRouter>);
    await screen.findByDisplayValue('Book Sarah for a haircut.');
    fireEvent.click(screen.getByRole('button', { name: /play/i }));
    expect(navigate).toHaveBeenCalledWith('/ask?sim=1');
  });
});
