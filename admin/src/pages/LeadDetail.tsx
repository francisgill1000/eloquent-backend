import { useEffect, useState, useCallback, type CSSProperties } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getLead, updateLeadStatus } from '@/lib/leads';
import type { Lead, LeadActivity, LeadStatus } from '@/types';

const STATUS_LABEL: Record<LeadStatus, string> = {
  new: 'New', sent: 'Sent', replied: 'Replied', demo: 'Demo', won: 'Won', pass: 'Pass',
};

// Crisp knob marks (match BookingAction): Won = check, Pass = X.
const KnobCheck = () => (
  <svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round"><path d="M5 12.5l4.2 4.2L19 6.5" /></svg>
);
const KnobX = () => (
  <svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3.2} strokeLinecap="round" strokeLinejoin="round"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11" /></svg>
);

// Vertical sliding-knob switch — top→bottom order = the funnel, Pass at the end.
// Colours mirror the s-<status> --stage tokens in leads.css.
const STAGE_OPTS: { status: LeadStatus; color: string }[] = [
  { status: 'new', color: 'var(--text-4)' },
  { status: 'sent', color: 'var(--info)' },
  { status: 'replied', color: 'var(--mint-300)' },
  { status: 'demo', color: 'var(--warn)' },
  { status: 'won', color: 'var(--mint-500)' },
  { status: 'pass', color: 'var(--danger)' },
];

// Main funnel path for the stepper. `pass` is the dead-end (out of funnel) and
// isn't a step — it's flagged separately on the card.
const FUNNEL: LeadStatus[] = ['new', 'sent', 'replied', 'demo', 'won'];

type StepState = 'done' | 'current' | 'todo';

function steps(status: LeadStatus): { label: string; state: StepState }[] {
  const active = FUNNEL.indexOf(status); // -1 when passed
  return FUNNEL.map((s, i) => ({
    label: STATUS_LABEL[s],
    state: status === 'won' ? 'done'
      : active < 0 ? 'todo'
      : i < active ? 'done' : i === active ? 'current' : 'todo',
  }));
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

  const setStatus = async (status: LeadStatus) => {
    if (!lead || status === lead.status || busy) return;
    setBusy(true); setError('');
    try {
      await updateLeadStatus(lead.id, status);
      await load();
    } catch {
      setError('Could not update status.');
    } finally {
      setBusy(false);
    }
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

  const wa = lead.whatsapp_url && lead.is_mobile ? lead.whatsapp_url : null;
  const passed = lead.status === 'pass';
  const activeIndex = STAGE_OPTS.findIndex((o) => o.status === lead.status);
  const stageColor = activeIndex >= 0 ? STAGE_OPTS[activeIndex].color : 'var(--text-4)';

  return (
    <div className="m-screen c-booking-action"><div className="m-scroll">
      <div className="ba-wrap">
        <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

        {error && <div className="c-error-box">{error}</div>}

        {/* Funnel stepper */}
        <div className={`ba-card ba-timeline-card${passed ? ' ld-passed' : ''}`}>
          <ol className="ba-timeline">
            {steps(lead.status).map((step, i) => (
              <li key={step.label} className={`ba-tstep ${step.state}`}>
                <span className="ba-tstep-in">
                  <span className="ba-tnode">{step.state === 'done' ? <Icons.Check size={16} /> : i + 1}</span>
                  <span className="ba-tmeta">
                    <span className="ba-tlabel">{step.label}</span>
                    <span className="ba-tstate">
                      {step.state === 'done' ? 'Done' : step.state === 'current' ? 'Current' : 'Pending'}
                    </span>
                  </span>
                </span>
              </li>
            ))}
          </ol>
          {passed && <div className="ld-passed-note"><span aria-hidden>✕</span> This lead was passed on.</div>}
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
              {wa && <a className="ld-act wa" href={wa} target="_blank" rel="noreferrer"><Icons.WhatsApp size={16} /> WhatsApp</a>}
              {lead.tel_url && <a className="ld-act" href={lead.tel_url}><Icons.Phone size={16} /> Call</a>}
              {lead.website && <a className="ld-act" href={lead.website} target="_blank" rel="noreferrer"><Icons.ArrowRight size={16} /> Website</a>}
              {lead.map_url && <a className="ld-act" href={lead.map_url} target="_blank" rel="noreferrer"><Icons.MapPin size={16} /> Map</a>}
            </div>

            <div className="ba-grid">
              <div className="ba-tile">
                <Icons.Phone size={15} />
                <span className="ba-tile-l">Phone</span>
                <span className="ba-tile-v">{lead.phone || '—'}</span>
              </div>
              <div className="ba-tile">
                <Icons.MapPin size={15} />
                <span className="ba-tile-l">Address</span>
                <span className="ba-tile-v">{lead.address || '—'}</span>
              </div>
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
            </div>
          </div>

          {/* Vertical sliding-knob stage switch — docked after the hero divider */}
          <div className={`ba-switch ld-switch${passed ? ' cancelled' : ''}`}
            style={{ '--active': activeIndex, '--stage': stageColor } as CSSProperties}>
            <div className="ba-switch-opts">
              {STAGE_OPTS.map((o) => {
                const on = o.status === lead.status;
                return (
                  <button key={o.status} type="button" aria-label={STATUS_LABEL[o.status]}
                    className={`ba-switch-opt${on ? ' on' : ''}`}
                    style={{ '--optc': o.color } as CSSProperties}
                    disabled={busy} onClick={() => void setStatus(o.status)}>
                    <span className="ba-switch-optlabel">{STATUS_LABEL[o.status]}</span>
                    <span className="ba-switch-optstate">{on ? 'Current' : 'Set'}</span>
                  </button>
                );
              })}
            </div>
            <div className="ba-switch-rail"><div className="ba-switch-fill" /></div>
            <div className="ba-switch-knob">
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
                    <span className="ld-log-dot" />
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
