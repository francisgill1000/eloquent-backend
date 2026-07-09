import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from '@/lib/api';
import { bookAssistantText, bookAssistantVoice, getPublicShop } from './publicBooking';

describe('publicBooking lib', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('normalizes an assistant reply', async () => {
    vi.spyOn(api, 'post').mockResolvedValue({ data: { reply_text: 'What day?', fields: { service: 'Cut' }, ready: false } });
    const res = await bookAssistantText(7, 'a cut', {});
    expect(res.reply_text).toBe('What day?');
    expect(res.fields.service).toBe('Cut');
    expect(res.ready).toBe(false);
  });

  it('defaults fields to an empty object when server omits them', async () => {
    vi.spyOn(api, 'post').mockResolvedValue({ data: { reply_text: 'Hi', ready: false } });
    const res = await bookAssistantText(7, 'hi', {});
    expect(res.fields).toEqual({});
  });

  it('fetches a public shop with an optional date param', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { id: 7, name: 'Acme', catalogs: [] } });
    const shop = await getPublicShop(7, '2026-07-12');
    expect(get).toHaveBeenCalledWith('/shops/7', { params: { date: '2026-07-12' } });
    expect(shop.name).toBe('Acme');
  });

  it('sends voice booking as multipart, not JSON', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { reply_text: 'Got it', ready: false } });
    const audio = new Blob(['bytes'], { type: 'audio/webm' });
    await bookAssistantVoice(7, audio, { service: 'Cut' });

    const [url, body, config] = post.mock.calls[0];
    expect(url).toBe('/shops/7/book-assistant/voice');
    expect(body).toBeInstanceOf(FormData);
    expect(config?.headers?.['Content-Type']).toBe('multipart/form-data');
  });
});
