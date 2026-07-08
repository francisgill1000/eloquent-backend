import api from './api';

export type SimTurn = { who: 'owner' | 'assistant'; text: string };
export type SimBooking = {
  customer_name: string; customer_phone: string; service: string; price: string;
  date: string; start_time: string; end_time: string; staff_name: string;
};
export type SimScript = {
  turns: SimTurn[];
  booking: SimBooking;
  voices: { owner: string; assistant: string };
  thinking_ms: number;
};

/** The shop's saved demo-simulation script (or a server-generated default). */
export async function getSimulation(): Promise<SimScript> {
  const { data } = await api.get('/shop/simulation');
  return data.script as SimScript;
}

/** Save the script; pass null to clear back to the generated default. */
export async function saveSimulation(script: SimScript | null): Promise<SimScript> {
  const { data } = await api.put('/shop/simulation', { script });
  return data.script as SimScript;
}

/** Voice one line of text; returns an object URL for the MP3 (caller revokes). */
export async function speak(text: string, voice: string): Promise<string> {
  const { data } = await api.post('/tts', { text, voice }, { responseType: 'blob' });
  return URL.createObjectURL(data as Blob);
}
