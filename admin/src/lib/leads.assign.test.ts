import { describe, expect, it, vi, beforeEach } from 'vitest';
import api from './api';
import { assignLead, assignLeadsBulk, setLeadAutoAssign, listLeads } from './leads';

vi.mock('./api', () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));

describe('lead assignment client', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('assigns one lead', async () => {
    (api.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { data: { id: 1 } } });
    await assignLead(1, 7);
    expect(api.patch).toHaveBeenCalledWith('/shop/leads/1/assign', { assigned_to_id: 7 });
  });

  it('unassigns with null', async () => {
    (api.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { data: { id: 1 } } });
    await assignLead(1, null);
    expect(api.patch).toHaveBeenCalledWith('/shop/leads/1/assign', { assigned_to_id: null });
  });

  it('bulk assigns', async () => {
    (api.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { assigned: 3 } });
    const n = await assignLeadsBulk([1, 2, 3], 7);
    expect(api.post).toHaveBeenCalledWith('/shop/leads/assign', { ids: [1, 2, 3], assigned_to_id: 7 });
    expect(n).toBe(3);
  });

  it('toggles auto-assign', async () => {
    (api.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { lead_auto_assign: true } });
    expect(await setLeadAutoAssign(true)).toBe(true);
    expect(api.patch).toHaveBeenCalledWith('/shop/leads/settings', { lead_auto_assign: true });
  });

  it('passes the owner filter through to the API', async () => {
    (api.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { data: [] } });
    await listLeads({ assigned_to: 'unassigned' });
    expect(api.get).toHaveBeenCalledWith('/shop/leads', { params: { assigned_to: 'unassigned' } });
  });

  it('defaults assignees and auto_assign when the API omits them', async () => {
    (api.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { data: [] } });
    const res = await listLeads();
    expect(res.assignees).toEqual([]);
    expect(res.auto_assign).toBe(false);
  });
});
