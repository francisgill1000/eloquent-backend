import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
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

/**
 * Query only the outreach action row. These assertions used to run against the
 * whole document, which also matched the funnel stage switch — it renders a
 * permanent button with aria-label "Follow-up" for the followup stage, so
 * "no outreach button" assertions failed on a control that is not outreach.
 */
function actions() {
  const row = document.querySelector('.ld-actions');
  if (!row) throw new Error('.ld-actions was not rendered');
  return within(row as HTMLElement);
}

describe('LeadDetail outreach button', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  // Task 11 replaced the single WhatsApp/Follow-up pair with a per-channel
  // action row (see `LeadDetail channel actions` below): every reachable
  // channel gets its own button that opens the link and logs a touch via
  // logTouch, instead of the old New→sendOpening / Sent→sendFollowup
  // special cases. Fix round 1 restored the New→Sent auto-advance as an
  // explicit second call (advanceIfFirstTouch), so a first outbound touch on
  // a New lead still moves it to Sent — same end result as before, just
  // sequenced as touch-then-advance instead of being the same API call.
  it('shows a WhatsApp action for a New lead: logs a touch and advances it to Sent', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const touch = vi.spyOn(leadsLib, 'logTouch').mockResolvedValue({ ...baseLead, status: 'new' });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'sent' });

    setup();
    await screen.findByText('Pak Cargo');
    await userEvent.click(actions().getByRole('button', { name: /whatsapp/i }));

    expect(window.open).toHaveBeenCalledWith(baseLead.whatsapp_url, '_blank');
    await waitFor(() => expect(touch).toHaveBeenCalledWith(3, 'whatsapp', 'out'));
    await waitFor(() => expect(setStatus).toHaveBeenCalledWith(3, 'sent'));
    // The touch is recorded before the stage advances, not the other way round.
    expect(touch.mock.invocationCallOrder[0]).toBeLessThan(setStatus.mock.invocationCallOrder[0]);
    expect(actions().queryByRole('button', { name: /^follow-up$/i })).not.toBeInTheDocument();
  });

  it('logs a touch for a Sent lead via the WhatsApp action without advancing its stage', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const touch = vi.spyOn(leadsLib, 'logTouch').mockResolvedValue({ ...baseLead, status: 'sent' });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus');

    setup();
    await screen.findByText('Pak Cargo');
    expect(actions().getByRole('button', { name: /^log a touch$/i })).toBeInTheDocument();
    expect(actions().getByRole('button', { name: /^they replied$/i })).toBeInTheDocument();

    await userEvent.click(actions().getByRole('button', { name: /whatsapp/i }));

    await waitFor(() => expect(touch).toHaveBeenCalledWith(3, 'whatsapp', 'out'));
    // Only a New lead's first outbound touch advances the funnel — a lead
    // already past New must never have its status changed by a touch.
    expect(setStatus).not.toHaveBeenCalled();
  });

  it('shows no outreach button for a Won lead', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'won' }, activities: [] });

    setup();
    await screen.findByText('Pak Cargo');
    expect(actions().queryByRole('button', { name: /whatsapp/i })).not.toBeInTheDocument();
    expect(actions().queryByRole('button', { name: /follow-up/i })).not.toBeInTheDocument();
  });

  it('shows no outreach button for a Not-Interested (pass) lead', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'pass' }, activities: [] });

    setup();
    await screen.findByText('Pak Cargo');
    expect(actions().queryByRole('button', { name: /whatsapp/i })).not.toBeInTheDocument();
    expect(actions().queryByRole('button', { name: /follow-up/i })).not.toBeInTheDocument();
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

  // The AI follow-up path used to call the deprecated logFollowup() client
  // (POST /shop/leads/{lead}/followup), a deploy-window alias scheduled for
  // deletion. It must log the equivalent modern touch instead, via logTouch,
  // so the path keeps working once the alias route is gone.
  it('personalizes a Sent lead: previews AI follow-up text, then opens WhatsApp and logs a touch', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const personalize = vi.spyOn(leadsLib, 'personalizeLead').mockResolvedValue('Just checking in, Pak Cargo!');
    const touch = vi.spyOn(leadsLib, 'logTouch').mockResolvedValue({ ...baseLead, status: 'sent' });
    const followup = vi.spyOn(leadsLib, 'logFollowup');

    setup();
    await userEvent.click(await screen.findByRole('button', { name: /personalize/i }));

    expect(await screen.findByText('Just checking in, Pak Cargo!')).toBeInTheDocument();
    expect(personalize).toHaveBeenCalledWith(3, 'followup');

    await userEvent.click(screen.getByRole('button', { name: /open whatsapp/i }));
    expect(window.open).toHaveBeenCalledWith(
      'https://wa.me/971501112233?text=' + encodeURIComponent('Just checking in, Pak Cargo!'),
      '_blank',
    );
    await waitFor(() => expect(touch).toHaveBeenCalledWith(3, 'whatsapp', 'out'));
    expect(followup).not.toHaveBeenCalled();
  });
});

describe('LeadDetail channel actions', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  // A lead with no phone at all — reachable only on Instagram. Before Task 11
  // this lead had NO way to log a touch, because the only outreach control was
  // gated on lead.is_mobile.
  const instagramOnlyLead = {
    ...baseLead, is_mobile: false, phone: null, tel_url: null,
    whatsapp_url: null, whatsapp_opening_url: null, whatsapp_followup_url: null,
    instagram: 'https://instagram.com/acmegym',
  };

  it('offers an Instagram action for a lead with no mobile', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...instagramOnlyLead, status: 'sent' }, activities: [] });

    setup();
    await screen.findByText('Pak Cargo');
    expect(actions().getByRole('button', { name: /instagram/i })).toBeInTheDocument();
  });

  it('does not offer channels the lead has no handle for', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...instagramOnlyLead, status: 'sent' }, activities: [] });

    setup();
    await screen.findByText('Pak Cargo');
    actions().getByRole('button', { name: /instagram/i });
    expect(actions().queryByRole('button', { name: /^linkedin$/i })).not.toBeInTheDocument();
  });

  it('names the channel and direction in the timeline', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({
      lead: { ...baseLead, status: 'replied' },
      activities: [
        { id: 2, type: 'contacted', channel: 'whatsapp', direction: 'in' },
        { id: 1, type: 'contacted', channel: 'instagram', direction: 'out' },
      ],
    });

    setup();
    expect(await screen.findByText(/You messaged them on Instagram/i)).toBeInTheDocument();
    expect(screen.getByText(/They replied on WhatsApp/i)).toBeInTheDocument();
  });

  it('falls back to Contacted for a touch with no channel', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({
      lead: { ...baseLead, status: 'sent' },
      activities: [{ id: 1, type: 'contacted' }],
    });

    setup();
    expect(await screen.findByText('Contacted')).toBeInTheDocument();
  });

  it('logs a touch via the channel picker for a Sent lead, and logs it even though window.open is stubbed to do nothing', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const touch = vi.spyOn(leadsLib, 'logTouch').mockResolvedValue({ ...baseLead, status: 'sent' });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus');

    setup();
    await screen.findByText('Pak Cargo');
    await userEvent.click(actions().getByRole('button', { name: /^log a touch$/i }));

    const dialog = await screen.findByRole('dialog', { name: /how did you contact them/i });
    await userEvent.click(within(dialog).getByRole('button', { name: /^whatsapp$/i }));

    // The touch is logged unconditionally — window.open here is a stub that
    // returns undefined (as a blocked popup would), and the call still happens.
    expect(window.open).toHaveBeenCalledWith(baseLead.whatsapp_url, '_blank');
    await waitFor(() => expect(touch).toHaveBeenCalledWith(3, 'whatsapp', 'out'));
    // Already Sent — the picker path must not advance the stage either.
    expect(setStatus).not.toHaveBeenCalled();
  });

  it('advances a New lead to Sent via the Log a touch picker too, not just the direct channel button', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const touch = vi.spyOn(leadsLib, 'logTouch').mockResolvedValue({ ...baseLead, status: 'new' });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'sent' });

    setup();
    await screen.findByText('Pak Cargo');
    await userEvent.click(actions().getByRole('button', { name: /^log a touch$/i }));

    const dialog = await screen.findByRole('dialog', { name: /how did you contact them/i });
    await userEvent.click(within(dialog).getByRole('button', { name: /^whatsapp$/i }));

    await waitFor(() => expect(touch).toHaveBeenCalledWith(3, 'whatsapp', 'out'));
    await waitFor(() => expect(setStatus).toHaveBeenCalledWith(3, 'sent'));
  });

  it('logs an inbound reply via the reply picker without advancing the stage', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const touch = vi.spyOn(leadsLib, 'logTouch').mockResolvedValue({ ...baseLead, status: 'sent' });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus');

    setup();
    await screen.findByText('Pak Cargo');
    await userEvent.click(actions().getByRole('button', { name: /^they replied$/i }));

    const dialog = await screen.findByRole('dialog', { name: /how did they reply/i });
    await userEvent.click(within(dialog).getByRole('button', { name: /^instagram$/i }));

    await waitFor(() => expect(touch).toHaveBeenCalledWith(3, 'instagram', 'in'));
    expect(setStatus).not.toHaveBeenCalled();
  });

  it('never advances a New lead on an inbound reply, even though a New lead is normally auto-advanced on outbound', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const touch = vi.spyOn(leadsLib, 'logTouch').mockResolvedValue({ ...baseLead, status: 'new' });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus');

    setup();
    await screen.findByText('Pak Cargo');
    await userEvent.click(actions().getByRole('button', { name: /^they replied$/i }));

    const dialog = await screen.findByRole('dialog', { name: /how did they reply/i });
    await userEvent.click(within(dialog).getByRole('button', { name: /^whatsapp$/i }));

    await waitFor(() => expect(touch).toHaveBeenCalledWith(3, 'whatsapp', 'in'));
    expect(setStatus).not.toHaveBeenCalled();
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
    // Scoped to the deal dialog — the page also has the (unrelated) contact
    // details editor's own Save button, which also matches /save/i.
    fireEvent.click(within(screen.getByRole('dialog', { name: /deal value/i })).getByRole('button', { name: /save|confirm/i }));

    await waitFor(() =>
      expect(spy).toHaveBeenCalledWith(expect.any(Number), 'won', undefined, {
        deal_amount: 300, deal_type: 'recurring', deal_term_months: 6,
      }, undefined),
    );
  });

  it('captures a one-off deal with no term months', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'demo' }, activities: [] });
    const spy = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'won' });

    setup();

    fireEvent.click(await screen.findByRole('button', { name: /^won$/i }));
    fireEvent.change(await screen.findByLabelText(/amount/i), { target: { value: '500' } });
    fireEvent.click(screen.getByRole('button', { name: /one-off/i }));
    // Scoped to the deal dialog — see the note in the recurring-deal test above.
    fireEvent.click(within(screen.getByRole('dialog', { name: /deal value/i })).getByRole('button', { name: /save|confirm/i }));

    await waitFor(() =>
      expect(spy).toHaveBeenCalledWith(expect.any(Number), 'won', undefined, {
        deal_amount: 500, deal_type: 'one_off',
      }, undefined),
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

    await waitFor(() => expect(spy).toHaveBeenCalledWith(expect.any(Number), 'won', undefined, undefined, undefined));
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

describe('LeadDetail contact details editor', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  it('mounts the contact details editor for a leads.manage user', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });

    setup();
    await screen.findByText('Pak Cargo');

    expect(screen.getByLabelText(/instagram/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^save$/i })).toBeInTheDocument();
  });

  it('renders the contact details editor read-only without leads.manage', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });

    setup(['leads.view']);
    await screen.findByText('Pak Cargo');

    expect(screen.getByLabelText(/instagram/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^save$/i })).not.toBeInTheDocument();
  });

  it('refreshes the lead after saving contact details', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const update = vi.spyOn(leadsLib, 'updateLead').mockResolvedValue({ ...baseLead, instagram: '@pakcargo' });

    setup();
    await screen.findByText('Pak Cargo');
    await userEvent.type(screen.getByLabelText(/instagram/i), '@pakcargo');
    await userEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() => expect(update).toHaveBeenCalledWith(3, expect.objectContaining({ instagram: '@pakcargo' })));
    // getLead is called once on initial load and again by onSaved's load().
    await waitFor(() => expect(leadsLib.getLead).toHaveBeenCalledTimes(2));
  });
});

describe('LeadDetail reply-channel prompt', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  it('asks how they replied when the funnel stage moves to Replied, and commits with the picked channel', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const spy = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'replied' });

    setup();
    await screen.findByText('Pak Cargo');
    fireEvent.click(screen.getByRole('button', { name: /^replied$/i }));

    // The status write must not fire until a channel is chosen (or skipped).
    expect(spy).not.toHaveBeenCalled();

    const dialog = await screen.findByRole('dialog', { name: /how did they reply/i });
    await userEvent.click(within(dialog).getByRole('button', { name: /^instagram$/i }));

    await waitFor(() => expect(spy).toHaveBeenCalledWith(3, 'replied', undefined, undefined, 'instagram'));
  });

  it('still commits the move to Replied when the channel prompt is dismissed', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const spy = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'replied' });

    setup();
    await screen.findByText('Pak Cargo');
    fireEvent.click(screen.getByRole('button', { name: /^replied$/i }));

    const dialog = await screen.findByRole('dialog', { name: /how did they reply/i });
    fireEvent.click(within(dialog).getByRole('button', { name: /^skip$/i }));

    await waitFor(() => expect(spy).toHaveBeenCalledWith(3, 'replied', undefined, undefined, undefined));
  });

  it('does not ask for a channel for any other stage move', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const spy = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'followup' });

    setup();
    await screen.findByText('Pak Cargo');
    fireEvent.click(screen.getByRole('button', { name: /^follow-up$/i }));

    await waitFor(() => expect(spy).toHaveBeenCalledWith(3, 'followup', undefined, undefined, undefined));
    expect(screen.queryByRole('dialog', { name: /how did they reply/i })).not.toBeInTheDocument();
  });
});

describe('LeadDetail assignment', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  const listWithSara = () =>
    vi.spyOn(leadsLib, 'listLeads').mockResolvedValue({
      data: [], funnel: { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 },
      pipelines: [], won_value: 0, assignees: [{ id: 7, name: 'Sara' }], auto_assign: false,
    });

  it('assigns the lead from the detail page', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    listWithSara();
    const assign = vi.spyOn(leadsLib, 'assignLead').mockResolvedValue({ ...baseLead });

    setup();
    const picker = await screen.findByLabelText('Assigned to');
    await waitFor(() => expect(screen.getByRole('option', { name: 'Sara' })).toBeInTheDocument());
    await userEvent.selectOptions(picker, '7');

    await waitFor(() => expect(assign).toHaveBeenCalledWith(3, 7));
  });

  it('shows a read-only owner to a user who cannot assign', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({
      lead: { ...baseLead, assigned_to: { id: 7, name: 'Sara' } }, activities: [],
    });
    const list = listWithSara();

    setup(['leads.view']);

    expect(await screen.findByText('Sara')).toBeInTheDocument();
    expect(screen.queryByLabelText('Assigned to')).not.toBeInTheDocument();
    // No assignee list is fetched for someone who cannot hand leads out.
    expect(list).not.toHaveBeenCalled();
  });

  it('renders an assignment in the activity timeline', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({
      lead: { ...baseLead },
      activities: [{ id: 1, type: 'assigned', payload: { to_name: 'Sara', from_name: 'Ali' }, created_at: null }],
    });
    listWithSara();

    setup();

    expect(await screen.findByText('Assigned to Sara (was Ali)')).toBeInTheDocument();
  });
});
