import api from './api';

export type PersonaInfo = {
  persona: string | null;
  /** What actually runs: the saved prompt, or the generated default when none is saved. */
  effective_prompt: string;
  using_custom: boolean;
};

/** The shop's AI assistant system prompt (drives WhatsApp + Live Chat replies). */
export async function getPersona(): Promise<PersonaInfo> {
  const { data } = await api.get('/shop/persona');
  return data;
}

/** Save the prompt; pass empty/null to fall back to the generated default. */
export async function savePersona(persona: string | null): Promise<PersonaInfo> {
  const { data } = await api.put('/shop/persona', { persona });
  return data;
}

/** Build a fresh prompt from the shop profile (services, hours, staff, location). Not saved. */
export async function generatePersona(): Promise<string> {
  const { data } = await api.get('/shop/persona/generate');
  return data?.prompt ?? '';
}
