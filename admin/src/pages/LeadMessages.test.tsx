import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import * as lib from '@/lib/leadMessages';
import LeadMessages from './LeadMessages';

function setup() {
  return render(<MemoryRouter><LeadMessages /></MemoryRouter>);
}

describe('LeadMessages', () => {
  beforeEach(() => { vi.restoreAllMocks(); });

  it('loads templates and saves edits', async () => {
    vi.spyOn(lib, 'getLeadMessages').mockResolvedValue({
      opening: null, followup: null,
      default_opening: 'Hi {name}, opening default', default_followup: 'Hi {name}, followup default',
    });
    const save = vi.spyOn(lib, 'saveLeadMessages').mockResolvedValue({
      opening: 'New opening {name}', followup: 'Hi {name}, followup default',
      default_opening: 'Hi {name}, opening default', default_followup: 'Hi {name}, followup default',
    });

    setup();
    // Falls back to defaults in the fields when nothing is saved.
    const opening = await screen.findByLabelText('Opening message');
    expect((opening as HTMLTextAreaElement).value).toBe('Hi {name}, opening default');

    await userEvent.clear(opening);
    // user-event v14 treats `{` as the start of special-key syntax, so a
    // literal `{` must be escaped as `{{`; `}` needs no escaping.
    await userEvent.type(opening, 'New opening {{name}');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    expect(save).toHaveBeenCalledWith('New opening {name}', 'Hi {name}, followup default');
  });
});
