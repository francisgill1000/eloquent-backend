import { useEffect, useState } from 'react';
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

function draftFrom(lead: Lead): Draft {
  return Object.fromEntries(FIELDS.map((f) => [f.key, lead[f.key] ?? ''])) as Draft;
}

/**
 * Contact details for a lead. Handles are sent raw — the server normalizes them
 * (one shared implementation, so the SPA and the voice path cannot disagree)
 * and returns 422 per field when a value cannot be interpreted. An empty
 * string legitimately clears a handle, so we never coerce '' away before
 * sending it.
 */
export function ContactDetails({ lead, canEdit, onSaved }: Props) {
  const [draft, setDraft] = useState<Draft>(() => draftFrom(lead));
  const [errors, setErrors] = useState<Partial<Record<FieldKey, string>>>({});
  // Page-level fallback for a rejection with no per-field payload (network
  // failure, 500, etc.) — without this the Save button just stops spinning
  // and the user has no way to tell whether the edit actually saved.
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  // Resync the draft when the lead's own contact fields actually change —
  // e.g. after a save round-trips through the server's normalizer and the
  // parent reloads with the canonical value. Depending on the field values
  // themselves (not the `lead` object reference, which changes on every
  // reload regardless of these fields) means an unrelated reload elsewhere
  // on the page never clobbers in-progress typing here.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => setDraft(draftFrom(lead)), [lead.id, ...FIELDS.map((f) => lead[f.key] ?? '')]);

  const save = async () => {
    setBusy(true);
    setErrors({});
    setError('');
    try {
      const saved = await updateLead(lead.id, draft);
      onSaved(saved);
    } catch (e) {
      const data = (e as { response?: { data?: { errors?: Partial<Record<FieldKey, string[]>>; message?: string } } })?.response?.data;
      const fieldErrors = data?.errors;
      if (fieldErrors && Object.keys(fieldErrors).length > 0) {
        setErrors(
          Object.fromEntries(
            Object.entries(fieldErrors).map(([k, v]) => [k, v?.[0]]),
          ) as Partial<Record<FieldKey, string>>,
        );
      } else {
        // No per-field payload — a network error, a 500, anything shaped
        // differently. Fall back to a generic message so Save failing is
        // never silent.
        setError(data?.message || 'Could not save contact details.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="ld-contact">
      <h3 className="ld-contact-title">Contact details</h3>
      {error && <div className="c-error-box">{error}</div>}
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
