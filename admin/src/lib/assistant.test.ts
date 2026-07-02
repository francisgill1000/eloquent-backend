import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { getHistory, clearHistory, postText, postVoice } from './assistant';

vi.mock('./api', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));

describe('assistant lib', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getHistory returns the messages array', async () => {
    (api.get as any).mockResolvedValue({ data: { messages: [{ id: 1, role: 'user', content: 'hi', audio_url: null }] } });
    const msgs = await getHistory();
    expect(api.get).toHaveBeenCalledWith('/shop/assistant/history');
    expect(msgs).toHaveLength(1);
    expect(msgs[0].content).toBe('hi');
  });

  it('clearHistory calls DELETE', async () => {
    (api.delete as any).mockResolvedValue({ data: { ok: true } });
    await clearHistory();
    expect(api.delete).toHaveBeenCalledWith('/shop/assistant/history');
  });

  it('postText sends only the text (no client history)', async () => {
    (api.post as any).mockResolvedValue({ data: { reply_text: 'hello', reply_audio_url: '/x.ogg' } });
    const out = await postText('hi');
    expect(api.post).toHaveBeenCalledWith('/shop/assistant/text', { text: 'hi' });
    expect(out.reply_text).toBe('hello');
  });

  it('postVoice posts multipart form data (no history field)', async () => {
    (api.post as any).mockResolvedValue({ data: { transcript: 'q', reply_text: 'a', reply_audio_url: null } });
    const blob = new Blob(['x'], { type: 'audio/webm' });
    await postVoice(blob);
    const [url, form, config] = (api.post as any).mock.calls[0];
    expect(url).toBe('/shop/assistant/voice');
    expect(form).toBeInstanceOf(FormData);
    expect(form.get('audio')).toBeInstanceOf(Blob);
    expect(form.get('history')).toBeNull();
    // Must override the api instance's application/json default so the
    // multipart body is parsed by the backend (regression guard).
    expect(config?.headers?.['Content-Type']).toBe('multipart/form-data');
  });
});
