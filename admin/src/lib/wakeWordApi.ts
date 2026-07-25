import api from './api';

export type WakeWordInfo = {
  /** The saved override, or null when the shop name is being used. */
  phrase: string | null;
  /** What actually gets listened for: the override, or the shop's name. */
  effective_phrase: string;
  using_custom: boolean;
};

/** The shop's voice wake phrase for the AI Summary page. */
export async function getWakeWord(): Promise<WakeWordInfo> {
  const { data } = await api.get('/shop/wake-word');
  return data;
}

/** Save the phrase; pass null to fall back to the shop's name. */
export async function saveWakeWord(phrase: string | null): Promise<WakeWordInfo> {
  const { data } = await api.put('/shop/wake-word', { phrase });
  return data;
}
