import api from './api';

export type AssistantMsg = { id: number; role: 'user' | 'assistant'; content: string; audio_url: string | null };
export type Conversation = { id: number; title: string; updated_at: string; source?: 'owner' | 'customer' };
export type AssistantReply = {
  conversation_id?: number;
  title?: string;
  transcript?: string;
  reply_text: string;
  reply_audio_url: string | null;
  action?: { type: 'navigate'; route: string };
};

export type ConversationPage = { conversations: Conversation[]; has_more: boolean };

/** One page (20) of the shop's threads, newest first. `q` filters by title
 *  server-side so search spans every page, not just the loaded ones. */
export async function listConversations(opts: { page?: number; q?: string } = {}): Promise<ConversationPage> {
  const { data } = await api.get('/shop/assistant/conversations', {
    params: { page: opts.page ?? 1, q: opts.q?.trim() || undefined },
  });
  return { conversations: (data.conversations ?? []) as Conversation[], has_more: !!data.has_more };
}

export async function getConversation(id: number): Promise<AssistantMsg[]> {
  const { data } = await api.get(`/shop/assistant/conversations/${id}`);
  return data.messages as AssistantMsg[];
}

export async function renameConversation(id: number, title: string): Promise<void> {
  await api.patch(`/shop/assistant/conversations/${id}`, { title });
}

export async function deleteConversation(id: number): Promise<void> {
  await api.delete(`/shop/assistant/conversations/${id}`);
}

export async function postText(text: string, conversationId?: number): Promise<AssistantReply> {
  const { data } = await api.post('/shop/assistant/text', { text, conversation_id: conversationId });
  return data;
}

export async function postVoice(audio: Blob, conversationId?: number): Promise<AssistantReply> {
  const form = new FormData();
  const ext = audio.type.split('/')[1]?.split(';')[0] || 'webm';
  form.append('audio', audio, `voice.${ext}`);
  if (conversationId != null) form.append('conversation_id', String(conversationId));
  // Override the shared api's JSON default so the FormData is sent as multipart.
  const { data } = await api.post('/shop/assistant/voice', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
}
