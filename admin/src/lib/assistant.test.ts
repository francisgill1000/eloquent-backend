import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { postText, postVoice } from './assistant';

vi.mock('./api');

describe('assistant lib', () => {
  beforeEach(() => vi.resetAllMocks());

  it('postText sends text + history and returns the reply', async () => {
    (api.post as any).mockResolvedValue({
      data: { transcript: 'hi', reply_text: 'hello', reply_audio_url: '/x.ogg', history: [] },
    });
    const out = await postText('hi', []);
    expect(api.post).toHaveBeenCalledWith('/shop/assistant/text', { text: 'hi', history: [] });
    expect(out.reply_text).toBe('hello');
  });

  it('postVoice posts multipart form data', async () => {
    (api.post as any).mockResolvedValue({
      data: { transcript: 'q', reply_text: 'a', reply_audio_url: null, history: [] },
    });
    const blob = new Blob(['x'], { type: 'audio/webm' });
    await postVoice(blob, []);
    const [url, form, config] = (api.post as any).mock.calls[0];
    expect(url).toBe('/shop/assistant/voice');
    expect(form).toBeInstanceOf(FormData);
    expect(form.get('audio')).toBeInstanceOf(Blob);
    expect(form.get('history')).toBe('[]');
    // Must override the api instance's application/json default so the
    // multipart body is parsed by the backend (regression guard).
    expect(config?.headers?.['Content-Type']).toBe('multipart/form-data');
  });
});
