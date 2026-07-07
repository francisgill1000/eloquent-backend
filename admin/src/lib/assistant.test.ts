import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import {
  listConversations, getConversation, renameConversation, deleteConversation, postText, postVoice,
} from './assistant';

vi.mock('./api', () => ({ default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

describe('assistant lib', () => {
  beforeEach(() => vi.clearAllMocks());

  it('listConversations returns the conversations array', async () => {
    (api.get as any).mockResolvedValue({ data: { conversations: [{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }] } });
    const list = await listConversations();
    expect(api.get).toHaveBeenCalledWith('/shop/assistant/conversations');
    expect(list).toHaveLength(1);
    expect(list[0].title).toBe('Booking help');
  });

  it('getConversation fetches one thread by id', async () => {
    (api.get as any).mockResolvedValue({ data: { messages: [{ id: 1, role: 'user', content: 'hi', audio_url: null }] } });
    const msgs = await getConversation(5);
    expect(api.get).toHaveBeenCalledWith('/shop/assistant/conversations/5');
    expect(msgs[0].content).toBe('hi');
  });

  it('renameConversation PATCHes the title', async () => {
    (api.patch as any).mockResolvedValue({ data: { ok: true, title: 'New' } });
    await renameConversation(5, 'New');
    expect(api.patch).toHaveBeenCalledWith('/shop/assistant/conversations/5', { title: 'New' });
  });

  it('deleteConversation DELETEs the thread', async () => {
    (api.delete as any).mockResolvedValue({ data: { ok: true } });
    await deleteConversation(5);
    expect(api.delete).toHaveBeenCalledWith('/shop/assistant/conversations/5');
  });

  it('postText sends text with the conversation id', async () => {
    (api.post as any).mockResolvedValue({ data: { conversation_id: 9, title: 'hi', reply_text: 'hello', reply_audio_url: '/x.ogg' } });
    const out = await postText('hi', 9);
    expect(api.post).toHaveBeenCalledWith('/shop/assistant/text', { text: 'hi', conversation_id: 9 });
    expect(out.reply_text).toBe('hello');
    expect(out.conversation_id).toBe(9);
  });

  it('postText omits the conversation id for a new thread', async () => {
    (api.post as any).mockResolvedValue({ data: { conversation_id: 1, title: 'hi', reply_text: 'hello', reply_audio_url: null } });
    await postText('hi');
    expect(api.post).toHaveBeenCalledWith('/shop/assistant/text', { text: 'hi', conversation_id: undefined });
  });

  it('postVoice posts multipart form data with the conversation id', async () => {
    (api.post as any).mockResolvedValue({ data: { transcript: 'q', reply_text: 'a', reply_audio_url: null, conversation_id: 2 } });
    const blob = new Blob(['x'], { type: 'audio/webm' });
    await postVoice(blob, 2);
    const [url, form, config] = (api.post as any).mock.calls[0];
    expect(url).toBe('/shop/assistant/voice');
    expect(form).toBeInstanceOf(FormData);
    expect(form.get('audio')).toBeInstanceOf(Blob);
    expect(form.get('conversation_id')).toBe('2');
    // Must override the api instance's application/json default so the
    // multipart body is parsed by the backend (regression guard).
    expect(config?.headers?.['Content-Type']).toBe('multipart/form-data');
  });

  it('postVoice omits the conversation id field for a new thread', async () => {
    (api.post as any).mockResolvedValue({ data: { transcript: 'q', reply_text: 'a', reply_audio_url: null, conversation_id: 1 } });
    const blob = new Blob(['x'], { type: 'audio/webm' });
    await postVoice(blob);
    const [, form] = (api.post as any).mock.calls[0];
    expect(form.get('conversation_id')).toBeNull();
  });
});
