import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as personaLib from '@/lib/persona';
import Assistant from './Assistant';

function setup() {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  return render(<MemoryRouter><ShopProvider><Assistant /></ShopProvider></MemoryRouter>);
}

const GENERATED = 'You are the friendly assistant for Acme, a salon business.\n\nSERVICES (only offer these):\n- Haircut — AED 50.00';

describe('Assistant', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('shows the generated default when no custom prompt is set', async () => {
    vi.spyOn(personaLib, 'getPersona').mockResolvedValue({
      persona: null, effective_prompt: GENERATED, using_custom: false,
    });

    setup();
    expect(await screen.findByText(/generated default/i)).toBeInTheDocument();
    expect(screen.getByLabelText('System prompt')).toHaveValue(GENERATED);
    expect(screen.queryByRole('button', { name: /clear & use generated default/i })).not.toBeInTheDocument();
  });

  it('saves a custom prompt', async () => {
    vi.spyOn(personaLib, 'getPersona').mockResolvedValue({
      persona: null, effective_prompt: GENERATED, using_custom: false,
    });
    const save = vi.spyOn(personaLib, 'savePersona').mockResolvedValue({
      persona: 'You are Bella.', effective_prompt: 'You are Bella.', using_custom: true,
    });

    setup();
    const box = await screen.findByLabelText('System prompt');
    const user = userEvent.setup();
    await user.clear(box);
    await user.type(box, 'You are Bella.');
    await user.click(screen.getByRole('button', { name: /save prompt/i }));

    expect(save).toHaveBeenCalledWith('You are Bella.');
    expect(await screen.findByText(/saved — your assistant now uses this prompt/i)).toBeInTheDocument();
    expect(screen.getByText('Custom')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /clear & use generated default/i })).toBeInTheDocument();
  });

  it('generates a prompt from the profile into the editor', async () => {
    vi.spyOn(personaLib, 'getPersona').mockResolvedValue({
      persona: null, effective_prompt: '', using_custom: false,
    });
    const gen = vi.spyOn(personaLib, 'generatePersona').mockResolvedValue(GENERATED);

    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /generate from profile/i }));

    expect(gen).toHaveBeenCalled();
    expect(await screen.findByText(/generated from your profile/i)).toBeInTheDocument();
    expect(screen.getByLabelText('System prompt')).toHaveValue(GENERATED);
  });

  it('clears back to the generated default', async () => {
    vi.spyOn(personaLib, 'getPersona').mockResolvedValue({
      persona: 'You are Bella.', effective_prompt: 'You are Bella.', using_custom: true,
    });
    const save = vi.spyOn(personaLib, 'savePersona').mockResolvedValue({
      persona: null, effective_prompt: GENERATED, using_custom: false,
    });

    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /clear & use generated default/i }));

    expect(save).toHaveBeenCalledWith(null);
    expect(await screen.findByText(/using the generated default/i)).toBeInTheDocument();
    expect(screen.getByLabelText('System prompt')).toHaveValue(GENERATED);
  });

  it('shows an error when saving fails', async () => {
    vi.spyOn(personaLib, 'getPersona').mockResolvedValue({
      persona: null, effective_prompt: GENERATED, using_custom: false,
    });
    vi.spyOn(personaLib, 'savePersona').mockRejectedValue(new Error('network'));

    setup();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /save prompt/i }));

    expect(await screen.findByText(/could not save/i)).toBeInTheDocument();
  });
});
