import api from './api';

export type BookingFields = {
  service?: string;
  date?: string;
  start_time?: string;
  customer_name?: string;
  customer_phone?: string;
};

export type AssistantReply = {
  transcript?: string;
  reply_text: string;
  fields: BookingFields;
  ready: boolean;
};

export type PublicShop = {
  id: number;
  name: string;
  logo?: string | null;
  catalogs?: Array<{ id: number; title: string; price: number | string }>;
  slots?: unknown;
};

/** Public shop read (name, logo, services, working hours, slots). No auth needed. */
export async function getPublicShop(id: number, date?: string): Promise<PublicShop> {
  const { data } = await api.get(`/shops/${id}`, { params: date ? { date } : undefined });
  return (data?.data ?? data) as PublicShop;
}

function normalize(d: unknown): AssistantReply {
  const o = (d ?? {}) as Record<string, unknown>;
  const fields = o.fields && typeof o.fields === 'object' ? (o.fields as BookingFields) : {};
  return {
    transcript: typeof o.transcript === 'string' ? o.transcript : undefined,
    reply_text: typeof o.reply_text === 'string' ? o.reply_text : '',
    fields,
    ready: !!o.ready,
  };
}

export async function bookAssistantText(shopId: number, text: string, state: BookingFields): Promise<AssistantReply> {
  const { data } = await api.post(`/shops/${shopId}/book-assistant/text`, { text, state });
  return normalize(data);
}

export async function bookAssistantVoice(shopId: number, audio: Blob, state: BookingFields): Promise<AssistantReply> {
  const fd = new FormData();
  fd.append('audio', audio, 'voice.webm');
  fd.append('state', JSON.stringify(state));
  const { data } = await api.post(`/shops/${shopId}/book-assistant/voice`, fd);
  return normalize(data);
}
