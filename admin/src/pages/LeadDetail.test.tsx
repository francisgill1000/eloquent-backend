import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as leadsLib from '@/lib/leads';
import LeadDetail from './LeadDetail';

const baseLead = {
  id: 3, name: 'Pak Cargo', phone: '+971 50 111 2233', status: 'new' as const,
  is_mobile: true, tel_url: 'tel:+971501112233',
  whatsapp_url: 'https://wa.me/971501112233',
  whatsapp_opening_url: 'https://wa.me/971501112233?text=Hi%20Pak%20Cargo',
  whatsapp_followup_url: 'https://wa.me/971501112233?text=Follow%20up%20Pak%20Cargo',
};

function setup() {
  return render(
    <MemoryRouter initialEntries={['/leads/3']}>
      <Routes><Route path="/leads/:id" element={<LeadDetail />} /></Routes>
    </MemoryRouter>,
  );
}

describe('LeadDetail outreach button', () => {
  beforeEach(() => { vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

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
});
