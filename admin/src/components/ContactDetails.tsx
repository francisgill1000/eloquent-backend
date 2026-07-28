import { useState } from 'react';
import { updateLead } from '@/lib/leads';
import type { Lead } from '@/types';

type FieldKey = 'phone' | 'whatsapp' | 'email' | 'instagram' | 'facebook' | 'tiktok' | 'linkedin' | 'website';

const FIELDS: { key: FieldKey; label: string; placeholder: string }[] = [
  { key: 'phone', label: 'Phone', placeholder: '050 111 2233' },
  { key: 'whatsapp', label: 'WhatsApp', placeholder: '050 111 2233' },
  { key: 'email', label: 'Email', placeholder: 'owner@business.ae' },
  { key: 'instagram', label: 'Instagram', placeholder: '@handle or link' },
  { key: 'facebook', label: 'Facebook', placeholder: '@handle or link' },
  { key: 'tiktok', label: 'TikTok', placeholder: '@handle or link' },
  { key: 'linkedin', label: 'LinkedIn', placeholder: 'company link' },
  { key: 'website', label: 'Website', placeholder: 'https://…' },
];

type Draft = Record<FieldKey, string>;

type Props = { lead: Lead; canEdit: boolean; onSaved: (lead: Lead) => void };

/**
 * Contact details for a lead. Handles are sent raw — the server normalizes them
 * (one shared implementation, so the SPA and the voice path cannot disagree)
 * and returns 422 per field when a value cannot be interpreted. An empty
 * string legitimately clears a handle, so we never coerce '' away before
 * sending it.
 */
export function ContactDetails({ lead, canEdit, onSaved }: Props) {
  const [draft, setDraft] = useState<Draft>(() =>
    Object.fromEntries(FIELDS.map((f) => [f.key, lead[f.key] ?? ''])) as Draft,
  );
  const [errors, setErrors] = useState<Partial<Record<FieldKey, string>>>({});
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      const saved = await updateLead(lead.id, draft);
      onSaved(saved);
    } catch (e) {
      const raw = (e as { response?: { data?: { errors?: Partial<Record<FieldKey, string[]>> } } })?.response?.data?.errors;
      setErrors(
        Object.fromEntries(
          Object.entries(raw ?? {}).map(([k, v]) => [k, v?.[0]]),
        ) as Partial<Record<FieldKey, string>>,
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="ld-contact">
      <h3 className="ld-contact-title">Contact details</h3>
      <div className="ld-contact-grid">
        {FIELDS.map((field) => (
          <label key={field.key} className="ld-contact-row">
            <span>{field.label}</span>
            <input
              aria-label={field.label}
              value={draft[field.key]}
              placeholder={field.placeholder}
              disabled={!canEdit || busy}
              onChange={(e) => setDraft({ ...draft, [field.key]: e.target.value })}
            />
            {errors[field.key] && <em className="ld-contact-err">{errors[field.key]}</em>}
          </label>
        ))}
      </div>
      {canEdit && (
        <button type="button" className="ld-act" disabled={busy} onClick={() => void save()}>
          {busy ? 'Saving…' : 'Save'}
        </button>
      )}
    </section>
  );
}
