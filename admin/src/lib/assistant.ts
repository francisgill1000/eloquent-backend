import api from './api';

export type AssistantMsg = { id: number; role: 'user' | 'assistant'; content: string; audio_url: string | null };
export type AssistantReply = { transcript?: string; reply_text: string; reply_audio_url: string | null };

export async function getHistory(): Promise<AssistantMsg[]> {
  const { data } = await api.get('/shop/assistant/history');
  return data.messages as AssistantMsg[];
}

export async function clearHistory(): Promise<void> {
  await api.delete('/shop/assistant/history');
}

export async function postText(text: string): Promise<AssistantReply> {
  const { data } = await api.post('/shop/assistant/text', { text });
  return data;
}

export async function postVoice(audio: Blob): Promise<AssistantReply> {
  const form = new FormData();
  const ext = audio.type.split('/')[1]?.split(';')[0] || 'webm';
  form.append('audio', audio, `voice.${ext}`);
  // The shared api instance defaults to application/json; override it so the
  // FormData is sent as multipart. axios appends the boundary for FormData
  // bodies (same pattern as the customer app's chat voice upload).
  const { data } = await api.post('/shop/assistant/voice', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
}
