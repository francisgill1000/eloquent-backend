import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as leadsLib from '@/lib/leads';
import LeadDetail from './LeadDetail';

const baseLead = {
  id: 3, name: 'Pak Cargo', phone: '+971 50 111 2233', status: 'new' as const,
  is_mobile: true, tel_url: 'tel:+971501112233',
  whatsapp_url: 'https://wa.me/971501112233',
  whatsapp_opening_url: 'https://wa.me/971501112233?text=Hi%20Pak%20Cargo',
  whatsapp_followup_url: 'https://wa.me/971501112233?text=Follow%20up%20Pak%20Cargo',
};

/** @param perms effective permissions for the acting user (default: full access). */
function setup(perms: string[] = ['*']) {
  storage.setJSON('shop_data', { id: 7, name: 'Acme' });
  storage.set('shop_token', 'tok');
  storage.setJSON('shop_permissions', perms);
  return render(
    <MemoryRouter initialEntries={['/leads/3']}>
      <ShopProvider>
        <Routes><Route path="/leads/:id" element={<LeadDetail />} /></Routes>
      </ShopProvider>
    </MemoryRouter>,
  );
}

describe('LeadDetail outreach button', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  it('shows WhatsApp (opening) for a New lead and marks it Sent on click', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'sent' });

    setup();
    const btn = await screen.findByRole('button', { name: /whatsapp/i });
    await userEvent.click(btn);

    expect(window.open).toHaveBeenCalledWith(baseLead.whatsapp_opening_url, '_blank');
    expect(setStatus).toHaveBeenCalledWith(3, 'sent');
    expect(screen.queryByRole('button', { name: /follow-up/i })).not.toBeInTheDocument();
  });

  it('shows Follow-up for a Sent lead and logs a follow-up on click', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const follow = vi.spyOn(leadsLib, 'logFollowup').mockResolvedValue({ ...baseLead, status: 'sent' });

    setup();
    const btn = await screen.findByRole('button', { name: /follow-up/i });
    await userEvent.click(btn);

    expect(window.open).toHaveBeenCalledWith(baseLead.whatsapp_followup_url, '_blank');
    expect(follow).toHaveBeenCalledWith(3);
  });

  it('shows no outreach button for a Won lead', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'won' }, activities: [] });

    setup();
    await screen.findByText('Pak Cargo');
    expect(screen.queryByRole('button', { name: /whatsapp/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /follow-up/i })).not.toBeInTheDocument();
  });

  it('shows no outreach button for a Not-Interested (pass) lead', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'pass' }, activities: [] });

    setup();
    await screen.findByText('Pak Cargo');
    expect(screen.queryByRole('button', { name: /whatsapp/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /follow-up/i })).not.toBeInTheDocument();
  });

  it('personalizes a New lead: previews AI text, then opens WhatsApp and marks Sent', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const personalize = vi.spyOn(leadsLib, 'personalizeLead').mockResolvedValue('Hi Pak Cargo, quick demo?');
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'sent' });

    setup();
    await userEvent.click(await screen.findByRole('button', { name: /personalize/i }));

    // Preview shows the AI message
    expect(await screen.findByText('Hi Pak Cargo, quick demo?')).toBeInTheDocument();
    expect(personalize).toHaveBeenCalledWith(3, 'opening');

    // Opening WhatsApp from the preview uses the AI text and advances the stage
    await userEvent.click(screen.getByRole('button', { name: /open whatsapp/i }));
    expect(window.open).toHaveBeenCalledWith(
      'https://wa.me/971501112233?text=' + encodeURIComponent('Hi Pak Cargo, quick demo?'),
      '_blank',
    );
    expect(setStatus).toHaveBeenCalledWith(3, 'sent');
  });
});

describe('LeadDetail permissions', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  it('leaves every write control inert for a leads.view-only user', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'sent' });
    const personalize = vi.spyOn(leadsLib, 'personalizeLead').mockResolvedValue('nope');

    setup(['leads.view']);
    await screen.findByText('Pak Cargo');

    expect(screen.getByRole('button', { name: /whatsapp/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /personalize/i })).toBeDisabled();

    // Tapping a funnel stage must not fire a status write either.
    fireEvent.click(screen.getByRole('button', { name: /^demo$/i }));
    await waitFor(() => expect(setStatus).not.toHaveBeenCalled());
    expect(personalize).not.toHaveBeenCalled();
  });

  it('keeps the write controls live for a leads.manage user', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });

    setup(['leads.view', 'leads.manage']);
    await screen.findByText('Pak Cargo');

    expect(screen.getByRole('button', { name: /whatsapp/i })).not.toBeDisabled();
    expect(screen.getByRole('button', { name: /personalize/i })).not.toBeDisabled();
  });
});

describe('LeadDetail won-deal capture', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  it('captures a recurring deal when marking a lead won', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'demo' }, activities: [] });
    const spy = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'won' });

    setup();

    fireEvent.click(await screen.findByRole('button', { name: /^won$/i }));
    fireEvent.change(await screen.findByLabelText(/amount/i), { target: { value: '300' } });
    fireEvent.click(screen.getByRole('button', { name: /recurring/i }));
    fireEvent.click(screen.getByRole('button', { name: /6 months/i }));
    fireEvent.click(screen.getByRole('button', { name: /save|confirm/i }));

    await waitFor(() =>
      expect(spy).toHaveBeenCalledWith(expect.any(Number), 'won', undefined, {
        deal_amount: 300, deal_type: 'recurring', deal_term_months: 6,
      }),
    );
  });

  it('captures a one-off deal with no term months', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'demo' }, activities: [] });
    const spy = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'won' });

    setup();

    fireEvent.click(await screen.findByRole('button', { name: /^won$/i }));
    fireEvent.change(await screen.findByLabelText(/amount/i), { target: { value: '500' } });
    fireEvent.click(screen.getByRole('button', { name: /one-off/i }));
    fireEvent.click(screen.getByRole('button', { name: /save|confirm/i }));

    await waitFor(() =>
      expect(spy).toHaveBeenCalledWith(expect.any(Number), 'won', undefined, {
        deal_amount: 500, deal_type: 'one_off',
      }),
    );
    const callArg = spy.mock.calls[0][3];
    expect(callArg).not.toHaveProperty('deal_term_months');
  });

  it('skips deal capture and wins the lead with no deal', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'demo' }, activities: [] });
    const spy = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'won' });

    setup();

    fireEvent.click(await screen.findByRole('button', { name: /^won$/i }));
    await screen.findByLabelText(/amount/i);
    fireEvent.click(screen.getByRole('button', { name: /^skip$/i }));

    await waitFor(() => expect(spy).toHaveBeenCalledWith(expect.any(Number), 'won', undefined, undefined));
  });

  it('cancels the won panel without committing any status change', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'demo' }, activities: [] });
    const spy = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'won' });

    setup();

    fireEvent.click(await screen.findByRole('button', { name: /^won$/i }));
    fireEvent.click(await screen.findByRole('button', { name: /^cancel$/i }));

    // Panel closes and no status update fires — lead stays where it was.
    await waitFor(() => expect(screen.queryByLabelText(/amount/i)).not.toBeInTheDocument());
    expect(spy).not.toHaveBeenCalled();
  });
});
