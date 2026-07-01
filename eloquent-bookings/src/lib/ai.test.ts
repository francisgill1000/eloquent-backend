import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { aiSearch } from './ai';

vi.mock('./api', () => ({ default: { post: vi.fn() } }));

describe('aiSearch', () => {
  beforeEach(() => vi.clearAllMocks());

  it('posts the message thread and coords', async () => {
    (api.post as any).mockResolvedValue({ data: { reply: 'hi', action: null, shops: [] } });

    await aiSearch([{ role: 'user', content: 'find a barber' }], { lat: 25, lon: 55 });

    expect(api.post).toHaveBeenCalledWith('/ai/search', {
      messages: [{ role: 'user', content: 'find a barber' }],
      lat: 25,
      lon: 55,
    });
  });

  it('returns the action from the response', async () => {
    (api.post as any).mockResolvedValue({ data: { reply: 'ok', action: { type: 'navigate', route: '/bookings' }, shops: [] } });

    const res = await aiSearch([{ role: 'user', content: 'open bookings' }]);

    expect(res.action).toEqual({ type: 'navigate', route: '/bookings' });
  });
});
