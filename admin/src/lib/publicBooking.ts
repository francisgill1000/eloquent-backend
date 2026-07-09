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

/** One prior conversation turn, sent so the assistant remembers context. */
export type Turn = { role: 'user' | 'assistant'; content: string };

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

export async function bookAssistantText(shopId: number, text: string, state: BookingFields, history: Turn[] = []): Promise<AssistantReply> {
  const { data } = await api.post(`/shops/${shopId}/book-assistant/text`, { text, state, history });
  return normalize(data);
}

/** Record the confirmed booking's reference into the saved conversation (best-effort). */
export async function recordBooking(shopId: number, bookingId: number): Promise<{ ok: boolean; reference?: string }> {
  const { data } = await api.post(`/shops/${shopId}/book-assistant/booked`, { booking_id: bookingId });
  return { ok: !!data?.ok, reference: typeof data?.reference === 'string' ? data.reference : undefined };
}

export async function bookAssistantVoice(shopId: number, audio: Blob, state: BookingFields, history: Turn[] = []): Promise<AssistantReply> {
  const fd = new FormData();
  fd.append('audio', audio, 'voice.webm');
  fd.append('state', JSON.stringify(state));
  fd.append('history', JSON.stringify(history));
  // Override the shared api's JSON default so the FormData is sent as multipart
  // (otherwise axios serializes it as JSON and the audio Blob is dropped).
  const { data } = await api.post(`/shops/${shopId}/book-assistant/voice`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return normalize(data);
}
