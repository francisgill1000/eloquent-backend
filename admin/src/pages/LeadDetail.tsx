import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getLead, updateLeadStatus } from '@/lib/leads';
import { LEAD_STATUSES } from '@/types';
import type { Lead, LeadActivity, LeadStatus } from '@/types';

const STATUS_LABEL: Record<LeadStatus, string> = {
  new: 'New', sent: 'Sent', replied: 'Replied', demo: 'Demo', won: 'Won', pass: 'Pass',
};

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
        </div>

        {/* Move stage — the primary action */}
        <div className="ba-section">
          <div className="ba-section-title">Move stage</div>
          <div className="ld-stages">
            {LEAD_STATUSES.map((s) => (
              <button key={s} className={`ld-stage s-${s}${lead.status === s ? ' on' : ''}`}
                disabled={busy} onClick={() => void setStatus(s)}>
                {lead.status === s && <Icons.Check size={13} />} {STATUS_LABEL[s]}
              </button>
            ))}
          </div>
        </div>

        {/* Activity history */}
        <div className="ba-section">
          <div className="ba-section-title">Activity</div>
          {activities.length === 0 ? (
            <div className="ba-card ld-empty">No activity yet.</div>
          ) : (
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
          )}
        </div>
      </div>
    </div></div>
  );
}
