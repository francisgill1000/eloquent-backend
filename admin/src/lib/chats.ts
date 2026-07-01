import api from './api';
import type { WaAccountInfo, WaContact, WaMessage } from '@/types';

export async function getWaAccount(): Promise<WaAccountInfo> {
  const { data } = await api.get('/shop/wa/account');
  return data;
}

export async function saveWaAccount(payload: {
  phone_number?: string;
  phone_number_id: string;
  waba_id?: string;
  token?: string;
}): Promise<WaAccountInfo> {
  const { data } = await api.post('/shop/wa/account', payload);
  return data;
}

export async function getWaContacts(): Promise<{ connected: boolean; data: WaContact[] }> {
  const { data } = await api.get('/shop/wa/contacts');
  return { connected: !!data?.connected, data: Array.isArray(data?.data) ? data.data : [] };
}

export async function getWaMessages(contactId: number, sinceId?: number): Promise<WaMessage[]> {
  const { data } = await api.get(`/shop/wa/contacts/${contactId}/messages`, {
    params: sinceId ? { since_id: sinceId } : undefined,
  });
  return Array.isArray(data?.data) ? data.data : [];
}

export async function sendWaMessage(contactId: number, text: string): Promise<WaMessage> {
  const { data } = await api.post(`/shop/wa/contacts/${contactId}/messages`, { text });
  return data?.data ?? data;
}

export async function markWaRead(contactId: number): Promise<void> {
  await api.post(`/shop/wa/contacts/${contactId}/read`);
}

/** Agent takeover toggle: pause (false) or resume (true) the AI for one thread. */
export async function setWaAiEnabled(contactId: number, enabled: boolean): Promise<WaContact> {
  const { data } = await api.post(`/shop/wa/contacts/${contactId}/ai`, { enabled });
  return data?.data ?? data;
}

/** Lead triage: set a contact's status (or null to clear back to "New"). */
export async function setWaLeadStatus(contactId: number, status: string | null): Promise<WaContact> {
  const { data } = await api.post(`/shop/wa/contacts/${contactId}/status`, { status });
  return data?.data ?? data;
}
