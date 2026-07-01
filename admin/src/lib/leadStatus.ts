// Lead triage statuses, shared by the chat thread picker and the Chats list
// badge/filter. Keep the values in sync with the backend validation in
// WaChatController::setLeadStatus (hot|warm|cold|follow_up|not_interested).

export type LeadStatus = 'hot' | 'warm' | 'cold' | 'follow_up' | 'not_interested';

export type LeadStatusDef = {
  value: LeadStatus;
  label: string;
  dot: string;   // emoji used as a compact badge
  color: string; // accent colour for chips/borders
};

export const LEAD_STATUSES: LeadStatusDef[] = [
  { value: 'hot', label: 'Hot lead', dot: '🔴', color: '#ef4444' },
  { value: 'warm', label: 'Warm lead', dot: '🟠', color: '#f97316' },
  { value: 'cold', label: 'Cold lead', dot: '🔵', color: '#3b82f6' },
  { value: 'follow_up', label: 'Needs follow-up', dot: '🟡', color: '#eab308' },
  { value: 'not_interested', label: 'Not interested', dot: '⚪', color: '#9ca3af' },
];

export const LEAD_STATUS_BY_VALUE: Record<LeadStatus, LeadStatusDef> =
  Object.fromEntries(LEAD_STATUSES.map((s) => [s.value, s])) as Record<LeadStatus, LeadStatusDef>;

/** Look up a status def from a raw (possibly null/unknown) value. */
export function leadStatusDef(value?: string | null): LeadStatusDef | null {
  return value ? LEAD_STATUS_BY_VALUE[value as LeadStatus] ?? null : null;
}
