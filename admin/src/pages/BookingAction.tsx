import { useEffect, useRef, useState, useCallback, type CSSProperties, type PointerEvent as ReactPointerEvent } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import {
  getBooking, setBookingStatus, reassignBooking, markInvoicePaid, invoicePdfUrl, updateBookingNotes,
} from '@/lib/bookings';
import { getStaff } from '@/lib/shops';
import { getCustomer, updateCustomer, type CustomerDetail } from '@/lib/customers';
import { statusKind } from '@/lib/calendar';
import { dragIndexFromPointer, snapIndex } from '@/lib/statusKnob';
import type { Booking, StaffMember } from '@/types';

// Crisp, bold marks for the switch knob (no inner circle — the knob is the
// circle), so Completed's check and Cancelled's X read at the same weight.
const KnobCheck = () => (
  <svg width={22} height={22} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round"><path d="M5 12.5l4.2 4.2L19 6.5" /></svg>
);
const KnobX = () => (
  <svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3.2} strokeLinecap="round" strokeLinejoin="round"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11" /></svg>
);
// Down-pointing chevron for the "drag me" hint (rotated 180° via CSS for the up
// hint). Rendered as a staggered stack of three so the affordance reads clearly.
const HintChevron = () => (
  <svg width={22} height={22} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round"><path d="M6 9l6 6 6-6" /></svg>
);
const HintStack = ({ dir }: { dir: 'up' | 'down' }) => (
  <span className={`ba-switch-hint ${dir}`} aria-hidden="true">
    <HintChevron /><HintChevron /><HintChevron />
  </span>
);

// Vertical sliding-knob switch config — order = top→bottom on the rail.
const SWITCH_OPTS: { label: string; color: string }[] = [
  { label: 'Queued', color: 'var(--warn)' },
  { label: 'Booked', color: 'var(--info)' },
  { label: 'Completed', color: 'var(--mint-400)' },
  { label: 'Cancelled', color: 'var(--danger)' },
];

// Booking lifecycle for the progress timeline. Cancelled swaps the terminal
// step; otherwise it's Queued → Booked → Completed.
function timelineSteps(status: string): { label: string; state: 'done' | 'current' | 'todo' | 'cancelled' }[] {
  const kind = statusKind(status);
  if (kind === 'cancelled') {
    return [
      { label: 'Queued', state: 'done' },
      { label: 'Booked', state: 'done' },
      { label: 'Cancelled', state: 'cancelled' },
    ];
  }
  const active = kind === 'queued' ? 0 : kind === 'booked' ? 1 : 2;
  return ['Queued', 'Booked', 'Completed'].map((label, i) => ({
    label,
    state: i < active ? 'done' : i === active ? (kind === 'completed' ? 'done' : 'current') : 'todo',
  }));
}

export default function BookingAction() {
  const { id } = useParams<{ id: string }>();
  const bookingId = Number(id);
  const navigate = useNavigate();
  const [booking, setBooking] = useState<Booking | null>(null);
  const [staff, setStaff] = useState<StaffMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  // Live fractional knob position while dragging the status switch (null = idle).
  const [dragPos, setDragPos] = useState<number | null>(null);
  const railTopRef = useRef(0); // .ba-switch top captured at pointer-down

  // Intake notes (per-visit) + the customer's durable notes/preferences.
  const [visitNotes, setVisitNotes] = useState('');
  const [visitSaving, setVisitSaving] = useState(false);
  const [visitSaved, setVisitSaved] = useState(false);
  const [customer, setCustomer] = useState<CustomerDetail | null>(null);
  const [custNotes, setCustNotes] = useState('');
  const [custSaving, setCustSaving] = useState(false);
  const [custSaved, setCustSaved] = useState(false);

  const fetchBooking = useCallback(async () => {
    try {
      const b = await getBooking(bookingId);
      setBooking(b);
      return b;
    } catch {
      setError('Could not load booking.');
      return null;
    }
  }, [bookingId]);

  useEffect(() => { void fetchBooking().finally(() => setLoading(false)); }, [fetchBooking]);

  useEffect(() => {
    const shopId = (booking as { shop_id?: number } | null)?.shop_id ?? booking?.shop?.id;
    if (!shopId) return;
    getStaff(shopId).then((list) => setStaff(list.filter((s) => s.is_active !== false))).catch(() => setStaff([]));
  }, [booking]);

  // Initialise notes + load the customer's durable notes when the booking loads.
  // Keyed on the booking id so status refreshes don't clobber unsaved edits.
  const bookingKey = booking?.id;
  useEffect(() => {
    if (!booking) return;
    setVisitNotes(String((booking as { notes?: string | null }).notes ?? ''));
    const shopId = (booking as { shop_id?: number }).shop_id ?? booking.shop?.id;
    const custId = (booking as { shop_customer_id?: number | null }).shop_customer_id;
    if (shopId && custId) {
      getCustomer(shopId, custId)
        .then((c) => { setCustomer(c); setCustNotes(c.notes ?? ''); })
        .catch(() => { /* non-fatal: no customer panel */ });
    } else {
      setCustomer(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [bookingKey]);

  const saveVisitNotes = async () => {
    setVisitSaving(true);
    setError('');
    try {
      await updateBookingNotes(bookingId, visitNotes);
      setVisitSaved(true);
    } catch {
      setError('Could not save notes.');
    } finally {
      setVisitSaving(false);
    }
  };

  const saveCustomerNotes = async () => {
    const shopId = (booking as { shop_id?: number } | null)?.shop_id ?? booking?.shop?.id;
    if (!shopId || !customer) return;
    setCustSaving(true);
    setError('');
    try {
      await updateCustomer(shopId, customer.id, { notes: custNotes });
      setCustSaved(true);
    } catch {
      setError('Could not save customer notes.');
    } finally {
      setCustSaving(false);
    }
  };

  const updateStatus = async (status: string) => {
    if (!window.confirm(`Mark this booking as "${status}"?`)) return;
    setBusy(true);
    setError('');
    try {
      await setBookingStatus(bookingId, status);
      await fetchBooking();
    } catch {
      setError('Failed to update status.');
    } finally {
      setBusy(false);
    }
  };

  const assign = async (member: StaffMember) => {
    const verb = (booking as { staff_id?: number } | null)?.staff_id ? 'Reassign' : 'Assign';
    if (!window.confirm(`${verb} this booking to ${member.name}?`)) return;
    setBusy(true);
    setError('');
    try {
      await reassignBooking(bookingId, member.id);
      await fetchBooking();
    } catch (e: unknown) {
      setError((e as { response?: { status?: number } })?.response?.status === 409
        ? 'That staff is already booked at this slot.'
        : 'Could not assign staff.');
    } finally {
      setBusy(false);
    }
  };

  const payInvoice = async () => {
    if (!booking?.invoice?.id) return;
    setBusy(true);
    setError('');
    try {
      await markInvoicePaid(booking.invoice.id);
      await fetchBooking();
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg || 'Could not mark invoice paid.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading booking…" /></div>;

  if (!booking) {
    return (
      <div className="m-screen"><div className="m-scroll">
        <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
        <p className="c-muted-center">Booking not found.</p>
      </div></div>
    );
  }

  const status = String(booking.status || 'Booked');
  const name = booking.customer?.name || booking.customer_name || 'Guest';
  const serviceList = booking.services?.map((s) => s.title || s.name).filter(Boolean) ?? [];
  const services = serviceList.length ? serviceList.join(', ') : '—';
  const switchIndex = SWITCH_OPTS.findIndex((o) => o.label.toLowerCase() === status.toLowerCase());
  const switchActive = switchIndex >= 0 ? switchIndex : 0;
  const switchColor = switchIndex >= 0 ? SWITCH_OPTS[switchIndex].color : 'var(--text-4)';
  // While dragging, the knob/fill follow the pointer's fractional position.
  const dragging = dragPos !== null;
  const knobPos = dragging ? dragPos : switchActive;

  // "Drag me" affordance: bounce chevrons toward where the knob can go, but only
  // while the booking is still live (Queued/Booked). Completed/Cancelled are
  // terminal, so no hint. It fades the moment a drag starts.
  const kind = statusKind(status);
  const showHint = !dragging && switchIndex >= 0 && (kind === 'queued' || kind === 'booked');
  const hintDown = showHint; // forward is always available from Queued and Booked
  const hintUp = showHint && kind === 'booked'; // Booked can also slide back up

  // Drag-to-set: grab the knob and slide it up/down the rail. On release it snaps
  // to the nearest status and commits via updateStatus (which confirms first), so a
  // cancelled confirm springs the knob back to the real status. Uses Pointer Events
  // for one mouse+touch path; setPointerCapture keeps tracking through fast moves.
  const onKnobDown = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (busy) return;
    const rail = e.currentTarget.closest('.ba-switch');
    railTopRef.current = rail ? rail.getBoundingClientRect().top : 0;
    e.currentTarget.setPointerCapture(e.pointerId);
    setDragPos(switchActive);
  };
  const onKnobMove = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (dragPos === null) return;
    setDragPos(dragIndexFromPointer(e.clientY, railTopRef.current));
  };
  const onKnobUp = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (dragPos === null) return;
    const idx = snapIndex(dragPos);
    setDragPos(null);
    if (e.currentTarget.hasPointerCapture(e.pointerId)) e.currentTarget.releasePointerCapture(e.pointerId);
    if (idx !== switchIndex) void updateStatus(SWITCH_OPTS[idx].label);
  };

  // Invoice state is driven off status (the source of truth): payable only when
  // issued/overdue — never paid or cancelled.
  const invStatus = (booking.invoice?.status ?? '').toLowerCase();
  const invPaid = invStatus === 'paid' || booking.invoice?.paid === true;
  const invCancelled = invStatus === 'cancelled';
  const invLabel = invPaid ? 'Paid' : invCancelled ? 'Cancelled'
    : booking.invoice?.status ? booking.invoice.status.charAt(0).toUpperCase() + booking.invoice.status.slice(1) : 'Unpaid';

  return (
    <div className="m-screen c-booking-action"><div className="m-scroll">
      <div className="ba-wrap">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

      {error && <div className="c-error-box">{error}</div>}

      {/* Progress timeline — at the top, no heading */}
      <div className="ba-card ba-timeline-card">
        <ol className="ba-timeline">
          {timelineSteps(status).map((step, i) => (
            <li key={step.label} className={`ba-tstep ${step.state}`}>
              <span className="ba-tstep-in">
                <span className="ba-tnode">
                  {step.state === 'done' ? <Icons.Check size={16} /> : step.state === 'cancelled' ? '✕' : i + 1}
                </span>
                <span className="ba-tmeta">
                  <span className="ba-tlabel">{step.label}</span>
                  <span className="ba-tstate">
                    {step.state === 'done' ? 'Done' : step.state === 'current' ? 'Current' : step.state === 'cancelled' ? 'Cancelled' : 'Pending'}
                  </span>
                </span>
              </span>
            </li>
          ))}
        </ol>
      </div>

      {/* Hero — details on the left, vertical status switch docked on the right */}
      <div className="ba-card ba-hero">
        <div className="ba-hero-main">
          <div className="ba-hero-top">
            <div className="ba-avatar">{name.charAt(0).toUpperCase()}</div>
            <div className="ba-hero-id">
              <span className="ba-name">{name}</span>
              <span className="ba-ref">{booking.booking_reference || 'Booking'}</span>
            </div>
            <span className={`c-chip c-chip-${status.toLowerCase()}`}>{status}</span>
          </div>

          <div className="ba-service">
            <span className="ba-tile-l">Service</span>
            <span className="ba-service-val">{services}</span>
          </div>

          <div className="ba-grid">
            <div className="ba-tile">
              <Icons.Calendar size={15} />
              <span className="ba-tile-l">Date</span>
              <span className="ba-tile-v">{booking.date || '—'}</span>
            </div>
            <div className="ba-tile">
              <Icons.Clock size={15} />
              <span className="ba-tile-l">Time</span>
              <span className="ba-tile-v">{booking.start_time || '—'}</span>
            </div>
            <div className="ba-tile">
              <Icons.User size={15} />
              <span className="ba-tile-l">Staff</span>
              <span className="ba-tile-v">{booking.staff?.name || 'Unassigned'}</span>
            </div>
            <div className="ba-tile ba-tile-amount">
              <Icons.Tag size={15} />
              <span className="ba-tile-l">Charges</span>
              <span className="ba-tile-v">AED {booking.charges ?? 0}</span>
            </div>
          </div>
        </div>

        {/* Sliding-knob status switch */}
        <div className={`ba-switch ${statusKind(status)}${switchIndex < 0 ? ' none' : ''}${dragging ? ' dragging' : ''}`}
          style={{ '--active': knobPos, '--stage': switchColor } as CSSProperties}>
          <div className="ba-switch-opts">
            {SWITCH_OPTS.map((o) => {
              const on = o.label.toLowerCase() === status.toLowerCase();
              return (
                <button key={o.label} type="button" aria-label={o.label}
                  className={`ba-switch-opt${on ? ' on' : ''}`}
                  style={{ '--optc': o.color } as CSSProperties}
                  disabled={busy} onClick={() => void updateStatus(o.label)}>
                  <span className="ba-switch-optlabel">{o.label}</span>
                  <span className="ba-switch-optstate">{on ? 'Current' : 'Set'}</span>
                </button>
              );
            })}
          </div>
          <div className="ba-switch-rail">
            <div className="ba-switch-fill" />
          </div>
          <div className="ba-switch-knob" role="slider" aria-label="Drag to set status"
            aria-valuemin={0} aria-valuemax={SWITCH_OPTS.length - 1} aria-valuenow={switchActive}
            aria-valuetext={switchIndex >= 0 ? status : undefined}
            onPointerDown={onKnobDown} onPointerMove={onKnobMove} onPointerUp={onKnobUp} onPointerCancel={onKnobUp}>
            {hintUp && <HintStack dir="up" />}
            {hintDown && <HintStack dir="down" />}
            {status.toLowerCase() === 'completed' ? <KnobCheck />
              : status.toLowerCase() === 'cancelled' ? <KnobX />
              : status.toLowerCase() === 'queued' ? <Icons.Clock size={18} />
              : <span className="ba-switch-dot" />}
          </div>
        </div>
      </div>

      {booking.invoice && (
        <div className="ba-section">
          <div className="ba-section-title">Invoice</div>
          <div className="ba-card ba-invoice">
            <div className="ba-invoice-info">
              <span className={`ba-invoice-icon${invPaid ? ' paid' : ''}`}><Icons.Tag size={18} /></span>
              <div className="ba-invoice-text">
                <span className="ba-tile-l">Payment</span>
                <span className={`ba-invoice-status${invPaid ? ' paid' : ''}`}>{invLabel}</span>
              </div>
            </div>
            <div className="ba-invoice-actions">
              <a className="ba-invoice-view" href={invoicePdfUrl(bookingId)} target="_blank" rel="noreferrer">
                <Icons.Download size={15} /> View invoice
              </a>
              {!invPaid && !invCancelled && (
                <button className="ba-invoice-btn" disabled={busy} onClick={() => void payInvoice()}>
                  <Icons.Check size={16} /> Mark as Paid
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Intake notes — per-visit + the customer's durable notes/preferences */}
      <div className="ba-section">
        <div className="ba-section-title">Notes</div>
        <div className="ba-card" style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 8 }}>
          <label className="c-field-label" htmlFor="visitNotes">This visit</label>
          <textarea id="visitNotes" rows={3} placeholder="Notes for this appointment (intake, requests)…"
            value={visitNotes}
            onChange={(e) => { setVisitNotes(e.target.value); setVisitSaved(false); }}
            style={{ width: '100%', background: 'none', border: '1px solid var(--line, #333)', borderRadius: 10, color: 'var(--text-1)', padding: 10, resize: 'vertical', font: 'inherit' }} />
          <button className="c-btn" style={{ alignSelf: 'flex-start', padding: '8px 14px' }} disabled={visitSaving} onClick={() => void saveVisitNotes()}>
            {visitSaving ? 'Saving…' : visitSaved ? 'Saved ✓' : 'Save visit notes'}
          </button>

          {customer && (
            <>
              <label className="c-field-label" htmlFor="custNotes" style={{ marginTop: 8 }}>About {customer.name || 'this customer'} (kept across visits)</label>
              <textarea id="custNotes" rows={3} placeholder="Allergies, preferences, history…"
                value={custNotes}
                onChange={(e) => { setCustNotes(e.target.value); setCustSaved(false); }}
                style={{ width: '100%', background: 'none', border: '1px solid var(--line, #333)', borderRadius: 10, color: 'var(--text-1)', padding: 10, resize: 'vertical', font: 'inherit' }} />
              {customer.preferences && Object.keys(customer.preferences).length > 0 && (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                  {Object.entries(customer.preferences).map(([k, v]) => (
                    <span key={k} className="c-chip c-chip-booked">{k}: {String(v)}</span>
                  ))}
                </div>
              )}
              <button className="c-btn" style={{ alignSelf: 'flex-start', padding: '8px 14px' }} disabled={custSaving} onClick={() => void saveCustomerNotes()}>
                {custSaving ? 'Saving…' : custSaved ? 'Saved ✓' : 'Save customer notes'}
              </button>
            </>
          )}
        </div>
      </div>

      {staff.length > 0 && (
        <div className="ba-section">
          <div className="ba-section-title">Assign staff</div>
          <div className="ba-staff-list">
            {staff.map((m) => {
              const assigned = booking.staff?.id === m.id;
              return (
                <button key={m.id} className={`ba-staff-row${assigned ? ' on' : ''}`} disabled={busy} onClick={() => void assign(m)}>
                  <span className="ba-staff-av">{(m.name || '?').charAt(0).toUpperCase()}</span>
                  <span className="ba-staff-name">{m.name}</span>
                  {assigned
                    ? <span className="ba-staff-tag"><Icons.Check size={14} /> Assigned</span>
                    : <span className="ba-staff-assign">Assign <Icons.ArrowRight size={13} /></span>}
                </button>
              );
            })}
          </div>
        </div>
      )}
      </div>
    </div></div>
  );
}
