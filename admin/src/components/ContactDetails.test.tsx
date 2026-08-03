import { useState } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ContactDetails } from './ContactDetails';
import * as leads from '@/lib/leads';
import type { Lead } from '@/types';

const lead: Lead = { id: 7, name: 'Acme', status: 'sent' };

beforeEach(() => vi.restoreAllMocks());

describe('ContactDetails', () => {
  it('saves an edited handle', async () => {
    const spy = vi.spyOn(leads, 'updateLead').mockResolvedValue({ ...lead, instagram: 'https://instagram.com/acmegym' });
    render(<ContactDetails lead={lead} canEdit onSaved={() => {}} />);

    await userEvent.type(screen.getByLabelText(/instagram/i), '@acmegym');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(spy).toHaveBeenCalledWith(7, expect.objectContaining({ instagram: '@acmegym' })));
  });

  it('surfaces a server rejection against the field', async () => {
    vi.spyOn(leads, 'updateLead').mockRejectedValue({
      response: { status: 422, data: { errors: { instagram: ["That doesn't look like a valid instagram handle or link."] } } },
    });
    render(<ContactDetails lead={lead} canEdit onSaved={() => {}} />);

    await userEvent.type(screen.getByLabelText(/instagram/i), 'https://example.com/x');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    expect(await screen.findByText(/doesn't look like a valid instagram/i)).toBeInTheDocument();
  });

  it('renders read-only without permission', () => {
    render(<ContactDetails lead={lead} canEdit={false} onSaved={() => {}} />);

    expect(screen.queryByRole('button', { name: /save/i })).not.toBeInTheDocument();
  });

  it('clears a handle by saving an empty value', async () => {
    const withInstagram: Lead = { ...lead, instagram: '@old' };
    const spy = vi.spyOn(leads, 'updateLead').mockResolvedValue({ ...withInstagram, instagram: '' });
    render(<ContactDetails lead={withInstagram} canEdit onSaved={() => {}} />);

    await userEvent.clear(screen.getByLabelText(/instagram/i));
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(spy).toHaveBeenCalledWith(7, expect.objectContaining({ instagram: '' })));
  });

  // Finding 1 (fix round 1): a rejection with no per-field `errors` payload —
  // a bare network failure, a 500, anything not shaped like a 422 — must
  // still surface something. Before the fix, setErrors({}) ran and the Save
  // button just stopped spinning with no visible feedback at all.
  it('surfaces a generic error when the rejection has no field-errors payload', async () => {
    vi.spyOn(leads, 'updateLead').mockRejectedValue(new Error('Network Error'));
    render(<ContactDetails lead={lead} canEdit onSaved={() => {}} />);

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    expect(await screen.findByText(/could not save/i)).toBeInTheDocument();
  });

  // Finding 2 (fix round 1): after a successful save, the field must show
  // what the server actually stored (the normalized value), not the raw
  // text the user typed — otherwise the input silently disagrees with the
  // database and the next save would re-send the stale raw value.
  it('shows the server-normalized value once the parent re-renders with the saved lead', async () => {
    const normalized: Lead = { ...lead, instagram: 'https://instagram.com/acmegym' };
    vi.spyOn(leads, 'updateLead').mockResolvedValue(normalized);

    // Stands in for LeadDetail: onSaved feeds the server's response back in
    // as the next `lead` prop, exactly like onSaved={() => void load()} does.
    function Harness() {
      const [current, setCurrent] = useState<Lead>(lead);
      return <ContactDetails lead={current} canEdit onSaved={setCurrent} />;
    }
    render(<Harness />);

    await userEvent.type(screen.getByLabelText(/instagram/i), '@acmegym');
    expect(screen.getByLabelText(/instagram/i)).toHaveValue('@acmegym');

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(screen.getByLabelText(/instagram/i)).toHaveValue('https://instagram.com/acmegym'));
  });
});
