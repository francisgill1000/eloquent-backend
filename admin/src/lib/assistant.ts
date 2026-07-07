import api from './api';

export type AssistantMsg = { id: number; role: 'user' | 'assistant'; content: string; audio_url: string | null };
export type Conversation = { id: number; title: string; updated_at: string };
export type AssistantReply = {
  conversation_id?: number;
  title?: string;
  transcript?: string;
  reply_text: string;
  reply_audio_url: string | null;
};

export async function listConversations(): Promise<Conversation[]> {
  const { data } = await api.get('/shop/assistant/conversations');
  return data.conversations as Conversation[];
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
