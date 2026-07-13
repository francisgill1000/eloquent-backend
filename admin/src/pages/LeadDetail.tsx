import { useEffect, useState, useCallback, useRef, type CSSProperties, type PointerEvent as ReactPointerEvent } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getLead, updateLeadStatus, logFollowup, personalizeLead } from '@/lib/leads';
import { DEAL_TERMS } from '@/types';
import type { DealInput, DealType, Lead, LeadActivity, LeadStatus } from '@/types';

const STATUS_LABEL: Record<LeadStatus, string> = {
  new: 'New', sent: 'Sent', followup: 'Follow-up', replied: 'Replied', demo: 'Demo', won: 'Won', pass: 'Not Interested',
};

// Crisp knob marks (match BookingAction): Won = check, Pass = X.
const KnobCheck = () => (
  <svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round"><path d="M5 12.5l4.2 4.2L19 6.5" /></svg>
);
const KnobX = () => (
  <svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3.2} strokeLinecap="round" strokeLinejoin="round"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11" /></svg>
);
// "Drag me" affordance (match BookingAction): a staggered stack of three
// chevrons that flows toward where the knob can slide. Rotated by CSS per
// direction — and laid sideways on the horizontal mobile rail.
const HintChevron = () => (
  <svg width={22} height={22} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round"><path d="M6 9l6 6 6-6" /></svg>
);
const HintStack = ({ dir }: { dir: 'up' | 'down' }) => (
  <span className={`ba-switch-hint ${dir}`} aria-hidden="true">
    <HintChevron /><HintChevron /><HintChevron />
  </span>
);

// Per-status colours (mirror the s-<status> --stage tokens in leads.css). Used
// by the stage switch and the activity dots so both read from one palette.
const STAGE_COLOR: Record<LeadStatus, string> = {
  new: 'var(--text-4)',
  sent: 'var(--info)',
  followup: 'var(--violet)',
  replied: 'var(--mint-300)',
  demo: 'var(--warn)',
  won: 'var(--mint-500)',
  pass: 'var(--danger)',
};

// Vertical sliding-knob switch — top→bottom order = the funnel, Pass at the end.
const STAGE_OPTS: { status: LeadStatus }[] = [
  { status: 'new' }, { status: 'sent' }, { status: 'followup' }, { status: 'replied' },
  { status: 'demo' }, { status: 'won' }, { status: 'pass' },
];

// Dot colour for a timeline row — the status it moved TO (else neutral mint).
function activityColor(a: LeadActivity): string {
  if (a.type === 'status_change' && a.payload?.to) {
    return STAGE_COLOR[a.payload.to as LeadStatus] ?? 'var(--mint-300)';
  }
  return 'var(--mint-300)';
}

// Main funnel path for the stepper. `pass` is the dead-end (out of funnel) and
// isn't a step — it's flagged separately on the card.
const FUNNEL: LeadStatus[] = ['new', 'sent', 'followup', 'replied', 'demo', 'won'];

type StepState = 'done' | 'current' | 'todo' | 'cancelled';

function steps(status: LeadStatus, activities: LeadActivity[]): { label: string; state: StepState }[] {
  const active = FUNNEL.indexOf(status);

  // Active lead — highlight its current position in the funnel.
  if (active >= 0) {
    return FUNNEL.map((s, i) => ({
      label: STATUS_LABEL[s],
      state: status === 'won' ? 'done' : i < active ? 'done' : i === active ? 'current' : 'todo',
    }));
  }

  // Not Interested — no "Won" outcome. Show the funnel up to where it actually
  // got (from history; every lead starts at New), then a red terminal in place
  // of Won. FUNNEL's last entry is 'won', which we drop and replace.
  let reached = 0;
  for (const a of activities) {
    if (a.type !== 'status_change') continue;
    for (const s of [a.payload?.from, a.payload?.to]) {
      const idx = FUNNEL.indexOf(s as LeadStatus);
      if (idx > reached) reached = idx;
    }
  }
  const out: { label: string; state: StepState }[] = FUNNEL.slice(0, -1).map((s, i) => ({
    label: STATUS_LABEL[s],
    state: i <= reached ? 'done' : 'todo',
  }));
  out.push({ label: STATUS_LABEL.pass, state: 'cancelled' });
  return out;
}

function fmtDate(iso?: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function activityText(a: LeadActivity): string {
  if (a.type === 'status_change') {
    const to = a.payload?.to ? STATUS_LABEL[a.payload.to as LeadStatus] ?? a.payload.to : '';
    const from = a.payload?.from ? STATUS_LABEL[a.payload.from as LeadStatus] ?? a.payload.from : '';
    return from ? `Moved from ${from} to ${to}` : `Set to ${to}`;
  }
  if (a.type === 'note') return a.payload?.note ?? 'Note added';
  if (a.type === 'contacted') return 'Contacted';
  return a.type;
}

export default function LeadDetail() {
  const { id } = useParams<{ id: string }>();
  const leadId = Number(id);
  const navigate = useNavigate();
  const [lead, setLead] = useState<Lead | null>(null);
  const [activities, setActivities] = useState<LeadActivity[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [aiText, setAiText] = useState<string | null>(null);
  const [aiKind, setAiKind] = useState<'opening' | 'followup'>('opening');
  const [aiBusy, setAiBusy] = useState(false);
  // Drag-to-set on the vertical stage switch: index the knob is being dragged
  // to (null = not dragging). Commit happens on release.
  const switchRef = useRef<HTMLDivElement>(null);
  const [dragIndex, setDragIndex] = useState<number | null>(null);
  // Won-deal capture: marking a lead Won opens this small panel instead of
  // committing the status immediately, so we can attach a deal value.
  const [wonModal, setWonModal] = useState(false);
  const [dealAmount, setDealAmount] = useState('');
  const [dealType, setDealType] = useState<DealType>('one_off');
  const [dealTerm, setDealTerm] = useState<number>(6);

  const load = useCallback(async () => {
    try {
      const res = await getLead(leadId);
      setLead(res.lead);
      setActivities(res.activities);
    } catch {
      setError('Could not load this lead.');
    }
  }, [leadId]);

  useEffect(() => { void load().finally(() => setLoading(false)); }, [load]);

  // Escape closes the won panel without committing (matches Cancel/backdrop).
  useEffect(() => {
    if (!wonModal) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') cancelWon(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [wonModal]);

  // Any status change but Won commits right away; Won opens the deal-capture
  // panel first (drag or tap both land here, so both get the panel).
  const setStatus = async (status: LeadStatus) => {
    if (!lead || status === lead.status || busy) return;
    if (status === 'won') {
      setDealAmount(''); setDealType('one_off'); setDealTerm(6);
      setWonModal(true);
      return;
    }
    await commitStatus(status);
  };

  const commitStatus = async (status: LeadStatus, deal?: DealInput) => {
    if (!lead) return;
    setBusy(true); setError('');
    try {
      await updateLeadStatus(lead.id, status, undefined, deal);
      await load();
    } catch {
      setError('Could not update status.');
    } finally {
      setBusy(false);
    }
  };

  // Save on the won panel: a positive amount attaches a deal, otherwise the
  // lead wins with no deal (same path Skip takes).
  const saveWon = async () => {
    const amount = parseFloat(dealAmount);
    const deal: DealInput | undefined = Number.isFinite(amount) && amount > 0
      ? { deal_amount: amount, deal_type: dealType, ...(dealType === 'recurring' ? { deal_term_months: dealTerm } : {}) }
      : undefined;
    setWonModal(false);
    await commitStatus('won', deal);
  };

  const skipWon = async () => {
    setWonModal(false);
    await commitStatus('won');
  };

  // Back out of the won panel WITHOUT committing — leaves the lead in its prior
  // status. Used by the Cancel button, backdrop click, and the Escape key, so an
  // accidental drag onto Won is never a trap. Never calls updateLeadStatus.
  const cancelWon = () => {
    setWonModal(false);
    setDealAmount(''); setDealType('one_off'); setDealTerm(6);
  };

  // New lead → open the opening draft, then optimistically move to Sent.
  const sendOpening = async () => {
    if (!lead || busy) return;
    if (lead.whatsapp_opening_url) window.open(lead.whatsapp_opening_url, '_blank');
    setBusy(true); setError('');
    try {
      await updateLeadStatus(lead.id, 'sent');
      await load();
    } catch {
      setError('Could not update status.');
    } finally {
      setBusy(false);
    }
  };

  // Already contacted → open the follow-up draft and log the nudge.
  const sendFollowup = async () => {
    if (!lead || busy) return;
    if (lead.whatsapp_followup_url) window.open(lead.whatsapp_followup_url, '_blank');
    setBusy(true); setError('');
    try {
      await logFollowup(lead.id);
      await load();
    } catch {
      setError('Could not log the follow-up.');
    } finally {
      setBusy(false);
    }
  };

  // The AI message we write matches the lead's current stage.
  const outreachKind = (): 'opening' | 'followup' => (lead?.status === 'new' ? 'opening' : 'followup');

  // Digits for the wa.me link (server already normalized whatsapp_url).
  const waDigits = (): string | null => {
    const m = lead?.whatsapp_url?.match(/wa\.me\/(\d+)/);
    return m ? m[1] : null;
  };

  const personalize = async () => {
    if (!lead || aiBusy) return;
    const kind = outreachKind();
    setAiBusy(true); setError('');
    try {
      const text = await personalizeLead(lead.id, kind);
      setAiKind(kind);
      setAiText(text);
    } catch {
      setError('Could not generate right now. Please try again.');
    } finally {
      setAiBusy(false);
    }
  };

  // Send the previewed AI message: open WhatsApp with it, then run the same
  // stage transition as the normal outreach button.
  const sendAi = async () => {
    if (!lead || !aiText || busy) return;
    const digits = waDigits();
    if (digits) window.open(`https://wa.me/${digits}?text=${encodeURIComponent(aiText)}`, '_blank');
    setBusy(true); setError('');
    try {
      if (aiKind === 'opening') await updateLeadStatus(lead.id, 'sent');
      else await logFollowup(lead.id);
      setAiText(null);
      await load();
    } catch {
      setError('Could not update status.');
    } finally {
      setBusy(false);
    }
  };

  // Nearest stage index to a pointer position — measured from the actual option
  // elements so it stays accurate regardless of CSS geometry. The rail is
  // vertical on desktop and horizontal on phone/tablet (the switch is wider than
  // it is tall there), so pick the axis from the switch's shape and compare on it.
  const indexFromPointer = (clientX: number, clientY: number): number => {
    const opts = switchRef.current?.querySelectorAll('.ba-switch-opt');
    if (!opts || opts.length === 0) return 0;
    const sr = switchRef.current!.getBoundingClientRect();
    const horizontal = sr.width > sr.height;
    let best = 0;
    let bestDist = Infinity;
    opts.forEach((el, i) => {
      const r = (el as HTMLElement).getBoundingClientRect();
      const dist = horizontal
        ? Math.abs(clientX - (r.left + r.width / 2))
        : Math.abs(clientY - (r.top + r.height / 2));
      if (dist < bestDist) { bestDist = dist; best = i; }
    });
    return best;
  };

  // Grab the knob/rail and slide. Tapping a stage label keeps its own onClick.
  const onSwitchPointerDown = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (busy) return;
    if ((e.target as HTMLElement).closest('.ba-switch-opts')) return; // label taps = clicks
    e.currentTarget.setPointerCapture(e.pointerId);
    setDragIndex(indexFromPointer(e.clientX, e.clientY));
  };
  const onSwitchPointerMove = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (dragIndex === null) return;
    setDragIndex(indexFromPointer(e.clientX, e.clientY));
  };
  const onSwitchPointerUp = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (dragIndex === null) return;
    const idx = dragIndex;
    setDragIndex(null);
    try { e.currentTarget.releasePointerCapture(e.pointerId); } catch { /* already released */ }
    const target = STAGE_OPTS[idx]?.status;
    if (target && lead && target !== lead.status) void setStatus(target);
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading lead…" /></div>;

  if (!lead) {
    return (
      <div className="m-screen"><div className="m-scroll">
        <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
        <p className="c-muted-center">{error || 'Lead not found.'}</p>
      </div></div>
    );
  }

  const passed = lead.status === 'pass';
  const activeIndex = STAGE_OPTS.findIndex((o) => o.status === lead.status);
  const stageColor = STAGE_COLOR[lead.status] ?? 'var(--text-4)';

  // "Drag me" chevrons — bounce toward where the knob can go, but only while the
  // lead is still live. Won/Pass are terminal outcomes, so no hint there. Fades
  // the instant a drag starts. Down = forward through the funnel, up = back.
  const showHint = dragIndex === null && lead.status !== 'won' && lead.status !== 'pass';
  const hintDown = showHint; // forward always available (Won/Pass sit below)
  const hintUp = showHint && activeIndex > 0; // can slide back if not at New

  return (
    <div className="m-screen c-booking-action"><div className="m-scroll">
      <div className="ba-wrap">
        <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

        {error && <div className="c-error-box">{error}</div>}

        {/* Won-deal capture panel — opens instead of committing when the lead
            is marked Won, so we can (optionally) attach a deal value. */}
        {wonModal && (
          <div className="ld-won-overlay" role="dialog" aria-label="Deal value"
            onClick={() => { if (!busy) cancelWon(); }}>
            <div className="ba-card ld-won-modal" onClick={(e) => e.stopPropagation()}>
              <div className="ld-won-head">🎉 Mark as Won</div>
              <p className="ld-won-sub">Add the deal value, or skip to win with no amount.</p>

              <label className="c-field-label" htmlFor="dealAmount">Amount (AED)</label>
              <div className="c-input-row">
                <input id="dealAmount" type="number" min="0" step="0.01" inputMode="decimal"
                  placeholder="0.00" value={dealAmount} disabled={busy}
                  onChange={(e) => setDealAmount(e.target.value)} />
              </div>

              <span className="c-field-label">Type</span>
              <div className="ld-won-toggle">
                <button type="button" className={`ld-won-seg${dealType === 'one_off' ? ' on' : ''}`}
                  disabled={busy} onClick={() => setDealType('one_off')}>One-off</button>
                <button type="button" className={`ld-won-seg${dealType === 'recurring' ? ' on' : ''}`}
                  disabled={busy} onClick={() => setDealType('recurring')}>Recurring</button>
              </div>

              {dealType === 'recurring' && (
                <>
                  <span className="c-field-label">Term</span>
                  <div className="ld-won-chips">
                    {DEAL_TERMS.map((t) => (
                      <button key={t} type="button" className={`ld-won-chip${dealTerm === t ? ' on' : ''}`}
                        disabled={busy} onClick={() => setDealTerm(t)}>
                        {t} {t === 1 ? 'month' : 'months'}
                      </button>
                    ))}
                  </div>
                </>
              )}

              <div className="ld-won-actions">
                <button type="button" className="c-btn-ghost" disabled={busy} onClick={() => cancelWon()}>Cancel</button>
                <button type="button" className="c-btn-ghost" disabled={busy} onClick={() => void skipWon()}>Skip</button>
                <button type="button" className="c-btn" disabled={busy} onClick={() => void saveWon()}>
                  {busy ? 'Saving…' : 'Save'}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Funnel stepper */}
        <div className="ba-card ba-timeline-card">
          <ol className="ba-timeline">
            {steps(lead.status, activities).map((step, i) => (
              <li key={step.label} className={`ba-tstep ${step.state}`}>
                <span className="ba-tstep-in">
                  <span className="ba-tnode">
                    {step.state === 'done' ? <Icons.Check size={16} />
                      : step.state === 'cancelled' ? <span aria-hidden>✕</span>
                      : i + 1}
                  </span>
                  <span className="ba-tmeta">
                    <span className="ba-tlabel">{step.label}</span>
                    <span className="ba-tstate">
                      {step.state === 'done' ? 'Done'
                        : step.state === 'current' ? 'Current'
                        : step.state === 'cancelled' ? 'Not interested'
                        : 'Pending'}
                    </span>
                  </span>
                </span>
              </li>
            ))}
          </ol>
        </div>

        {/* Hero — identity, quick actions, details */}
        <div className="ba-card ba-hero">
          <div className="ba-hero-main">
            <div className="ba-hero-top">
              <div className="ba-avatar">{lead.name.charAt(0).toUpperCase()}</div>
              <div className="ba-hero-id">
                <span className="ba-name">{lead.name}</span>
                {lead.category && <span className="ba-ref">{lead.category}</span>}
              </div>
              <span className={`lf-status s-${lead.status}`}>{STATUS_LABEL[lead.status]}</span>
            </div>

            <div className="ld-actions">
              {lead.is_mobile && lead.status === 'new' && (
                <button type="button" className="ld-act wa" disabled={busy} onClick={() => void sendOpening()}>
                  <Icons.WhatsApp size={16} /> WhatsApp
                </button>
              )}
              {lead.is_mobile && (lead.status === 'sent' || lead.status === 'followup' || lead.status === 'replied' || lead.status === 'demo') && (
                <button type="button" className="ld-act wa" disabled={busy} onClick={() => void sendFollowup()}>
                  <Icons.WhatsApp size={16} /> Follow-up
                </button>
              )}
              {lead.is_mobile && (lead.status === 'new' || lead.status === 'sent' || lead.status === 'followup' || lead.status === 'replied' || lead.status === 'demo') && (
                <button type="button" className="ld-act" disabled={aiBusy || busy} onClick={() => void personalize()}>
                  <Icons.Sparkle size={16} /> {aiBusy ? 'Writing…' : 'Personalize'}
                </button>
              )}
              {lead.tel_url && <a className="ld-act" href={lead.tel_url}><Icons.Phone size={16} /> Call</a>}
              {lead.website && <a className="ld-act" href={lead.website} target="_blank" rel="noreferrer"><Icons.ArrowRight size={16} /> Website</a>}
              {lead.map_url && <a className="ld-act" href={lead.map_url} target="_blank" rel="noreferrer"><Icons.MapPin size={16} /> Map</a>}
            </div>

            {aiText && (
              <div className="ba-card" style={{ padding: 14, marginTop: 12, display: 'flex', flexDirection: 'column', gap: 10 }}>
                <div className="c-field-label" style={{ margin: 0 }}>AI {aiKind === 'opening' ? 'opening' : 'follow-up'} — review before sending</div>
                <p style={{ margin: 0, whiteSpace: 'pre-wrap', color: 'var(--text-1)', fontSize: 13.5, lineHeight: 1.5 }}>{aiText}</p>
                <div style={{ display: 'flex', gap: 8 }}>
                  <button type="button" className="ld-act wa" disabled={busy} onClick={() => void sendAi()}>
                    <Icons.WhatsApp size={16} /> Open WhatsApp
                  </button>
                  <button type="button" className="c-btn-ghost" disabled={aiBusy} onClick={() => void personalize()}>
                    {aiBusy ? 'Writing…' : 'Regenerate'}
                  </button>
                </div>
              </div>
            )}

            <div className="ba-grid ld-grid">
              <div className="ba-tile">
                <Icons.Phone size={15} />
                <span className="ba-tile-l">Phone</span>
                <span className="ba-tile-v">{lead.phone || '—'}</span>
              </div>
              {lead.pipeline && (
                <div className="ba-tile">
                  <Icons.List size={15} />
                  <span className="ba-tile-l">Pipeline</span>
                  <span className="ba-tile-v">{lead.pipeline}</span>
                </div>
              )}
              <div className="ba-tile">
                <Icons.Calendar size={15} />
                <span className="ba-tile-l">Added</span>
                <span className="ba-tile-v">{fmtDate(lead.created_at)}</span>
              </div>
              <div className="ba-tile">
                <Icons.Clock size={15} />
                <span className="ba-tile-l">Last contacted</span>
                <span className="ba-tile-v">{fmtDate(lead.last_contacted_at)}</span>
              </div>
              <div className="ba-tile ld-tile-address">
                <Icons.MapPin size={15} />
                <span className="ba-tile-l">Address</span>
                <span className="ba-tile-v">{lead.address || '—'}</span>
              </div>
            </div>
          </div>

          {/* Vertical sliding-knob stage switch — tap a stage or drag the knob. */}
          <div ref={switchRef} className={`ba-switch ld-switch${passed ? ' cancelled' : ''}${dragIndex !== null ? ' dragging' : ''}`}
            style={{ '--active': dragIndex ?? activeIndex, '--stage': stageColor } as CSSProperties}
            onPointerDown={onSwitchPointerDown} onPointerMove={onSwitchPointerMove}
            onPointerUp={onSwitchPointerUp} onPointerCancel={onSwitchPointerUp}>
            <div className="ba-switch-opts">
              {STAGE_OPTS.map((o, i) => {
                const on = i === (dragIndex ?? activeIndex);
                return (
                  <button key={o.status} type="button" aria-label={STATUS_LABEL[o.status]}
                    className={`ba-switch-opt${on ? ' on' : ''}`}
                    style={{ '--optc': STAGE_COLOR[o.status] } as CSSProperties}
                    disabled={busy} onClick={() => void setStatus(o.status)}>
                    <span className="ba-switch-optlabel">{STATUS_LABEL[o.status]}</span>
                    <span className="ba-switch-optstate">{on ? 'Current' : 'Set'}</span>
                  </button>
                );
              })}
            </div>
            <div className="ba-switch-rail"><div className="ba-switch-fill" /></div>
            <div className="ba-switch-knob">
              {hintUp && <HintStack dir="up" />}
              {hintDown && <HintStack dir="down" />}
              {lead.status === 'won' ? <KnobCheck />
                : lead.status === 'pass' ? <KnobX />
                : <span className="ba-switch-dot" />}
            </div>
          </div>
        </div>

        {/* Activity history */}
        <div className="ba-section">
          <div className="ba-section-title">Activity</div>
          {activities.length === 0 ? (
            <div className="ba-card ld-empty">No activity yet.</div>
          ) : (
            <div className="ba-card ld-log-card">
              <ol className="ld-log">
                {activities.map((a) => (
                  <li key={a.id} className="ld-log-row">
                    <span className="ld-log-dot" style={{ background: activityColor(a) }} />
                    <div className="ld-log-body">
                      <span className="ld-log-text">{activityText(a)}</span>
                      <span className="ld-log-time">{fmtDate(a.created_at)}</span>
                    </div>
                  </li>
                ))}
              </ol>
            </div>
          )}
        </div>
      </div>
    </div></div>
  );
}
