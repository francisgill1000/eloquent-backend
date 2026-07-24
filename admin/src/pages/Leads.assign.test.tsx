import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import Leads from './Leads';
import * as leadsLib from '@/lib/leads';
import type { Lead } from '@/types';

const EMPTY_FUNNEL = { new: 2, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 };

vi.mock('@/context/ShopContext', () => ({
  useShop: () => ({ shop: { id: 1, name: 'Test Shop' }, can: () => true }),
}));

const lead = (id: number, name: string, owner: { id: number; name: string } | null) =>
  ({ id, name, status: 'new', assigned_to: owner }) as unknown as Lead;

/** Open the Pipeline tab — the page lands on Find. */
async function openPipeline() {
  await userEvent.click(screen.getByRole('tab', { name: /pipeline/i }));
}

describe('Leads pipeline assignment', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
    vi.spyOn(leadsLib, 'listLeads').mockResolvedValue({
      data: [lead(1, 'Acme Salon', { id: 7, name: 'Sara' }), lead(2, 'Pool Co', null)],
      funnel: EMPTY_FUNNEL,
      pipelines: [],
      won_value: 0,
      assignees: [{ id: 7, name: 'Sara' }],
      auto_assign: false,
    });
    vi.spyOn(leadsLib, 'getLeadCredits').mockResolvedValue({
      credits: 50, can_purchase: false, embedded_checkout: false, packs: [],
    });
  });

  it('shows the owner on each lead and Unassigned when there is none', async () => {
    render(<MemoryRouter><Leads /></MemoryRouter>);
    await openPipeline();

    // Scope to the chip — the name also appears in the filter/assign dropdowns.
    await waitFor(() => expect(screen.getByText('Sara', { selector: '.lf-owner' })).toBeInTheDocument());
    expect(screen.getByText('Unassigned', { selector: '.lf-owner' })).toBeInTheDocument();
  });

  it('bulk assigns the selected leads', async () => {
    const bulk = vi.spyOn(leadsLib, 'assignLeadsBulk').mockResolvedValue(1);
    render(<MemoryRouter><Leads /></MemoryRouter>);
    await openPipeline();
    await waitFor(() => expect(screen.getByText('Acme Salon')).toBeInTheDocument());

    await userEvent.click(screen.getByLabelText('Select Acme Salon'));
    await userEvent.selectOptions(screen.getByLabelText('Assign selected to'), '7');

    await waitFor(() => expect(bulk).toHaveBeenCalledWith([1], 7));
  });

  it('filters by owner', async () => {
    render(<MemoryRouter><Leads /></MemoryRouter>);
    await openPipeline();
    await waitFor(() => expect(screen.getByText('Acme Salon')).toBeInTheDocument());

    await userEvent.selectOptions(screen.getByLabelText('Filter by owner'), 'unassigned');

    await waitFor(() =>
      expect(leadsLib.listLeads).toHaveBeenCalledWith(expect.objectContaining({ assigned_to: 'unassigned' })),
    );
  });

  it('toggles auto-assign', async () => {
    const toggle = vi.spyOn(leadsLib, 'setLeadAutoAssign').mockResolvedValue(true);
    render(<MemoryRouter><Leads /></MemoryRouter>);
    await openPipeline();
    await waitFor(() => expect(screen.getByText('Acme Salon')).toBeInTheDocument());

    await userEvent.click(screen.getByLabelText('Auto-assign new leads'));

    await waitFor(() => expect(toggle).toHaveBeenCalledWith(true));
  });

  it('opens pre-filtered when the URL carries a followups filter', async () => {
    render(<MemoryRouter initialEntries={['/leads?followups=overdue']}><Leads /></MemoryRouter>);

    await waitFor(() =>
      expect(leadsLib.listLeads).toHaveBeenCalledWith(expect.objectContaining({ followups: 'overdue' })),
    );
  });

  it('opens pre-filtered on stale', async () => {
    render(<MemoryRouter initialEntries={['/leads?stale=1']}><Leads /></MemoryRouter>);

    await waitFor(() =>
      expect(leadsLib.listLeads).toHaveBeenCalledWith(expect.objectContaining({ stale: true })),
    );
  });

  it('opens pre-filtered on unassigned', async () => {
    render(<MemoryRouter initialEntries={['/leads?assigned_to=unassigned']}><Leads /></MemoryRouter>);

    await waitFor(() =>
      expect(leadsLib.listLeads).toHaveBeenCalledWith(expect.objectContaining({ assigned_to: 'unassigned' })),
    );
  });
});
