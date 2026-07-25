import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as api from '@/lib/wakeWordApi';
import WakeWordSettings from './WakeWordSettings';

const navigate = vi.fn();
vi.mock('react-router-dom', async (orig) => ({ ...(await orig() as object), useNavigate: () => navigate }));
vi.mock('@/lib/wakeWordApi');

const unset: api.WakeWordInfo = { phrase: null, effective_phrase: 'Northside Barbers', using_custom: false };

/**
 * A fake `SpeechRecognition` matching the shape `@/lib/speechRecognition`
 * feature-detects (the same shape Task 4's hook installs on `window`).
 * Each construction is pushed onto `instances` so a test can grab the most
 * recent one and drive its `onresult`/`onerror`/`onend` callbacks directly.
 */
class FakeRecognition {
  static instances: FakeRecognition[] = [];
  continuous = false;
  interimResults = false;
  lang = '';
  onresult: ((e: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null = null;
  onend: (() => void) | null = null;
  onerror: ((e: { error: string }) => void) | null = null;
  start = vi.fn();
  stop = vi.fn();
  constructor() { FakeRecognition.instances.push(this); }
}

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

  describe('Test it', () => {
    beforeEach(() => {
      FakeRecognition.instances = [];
      (window as unknown as { SpeechRecognition: typeof FakeRecognition }).SpeechRecognition = FakeRecognition;
    });

    afterEach(() => {
      delete (window as unknown as { SpeechRecognition?: typeof FakeRecognition }).SpeechRecognition;
    });

    it('reports a blocked microphone instead of "would not wake it" when recognition errors', async () => {
      render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
      await screen.findByPlaceholderText('Northside Barbers');
      fireEvent.click(screen.getByRole('button', { name: /test it/i }));

      const rec = FakeRecognition.instances[FakeRecognition.instances.length - 1];
      act(() => { rec.onerror?.({ error: 'not-allowed' }); });
      act(() => { rec.onend?.(); });

      expect(await screen.findByText(/microphone access was blocked/i)).toBeInTheDocument();
      expect(screen.queryByText(/would not wake it/i)).toBeNull();
    });

    it('reports a hit on a clean run where the phrase is heard', async () => {
      render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
      await screen.findByPlaceholderText('Northside Barbers');
      fireEvent.click(screen.getByRole('button', { name: /test it/i }));

      const rec = FakeRecognition.instances[FakeRecognition.instances.length - 1];
      act(() => { rec.onresult?.({ results: [[{ transcript: 'Northside Barbers' }]] }); });
      act(() => { rec.onend?.(); });

      expect(await screen.findByText(/would wake it/i)).toBeInTheDocument();
    });
  });
});
