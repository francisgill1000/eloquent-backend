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
});
