import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import {
  getBooking, setBookingStatus, reassignBooking, markInvoicePaid,
} from '@/lib/bookings';
import { getStaff } from '@/lib/shops';
import { statusKind } from '@/lib/calendar';
import type { Booking, StaffMember } from '@/types';

const STATUS_OPTIONS = ['Booked', 'Completed', 'Cancelled'];

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
    } catch {
      setError('Could not mark invoice paid.');
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

  return (
    <div className="m-screen c-booking-action"><div className="m-scroll">
      <div className="ba-wrap">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

      {error && <div className="c-error-box">{error}</div>}

      {/* Hero — customer, reference, status + the key facts as tiles */}
      <div className="ba-card ba-hero">
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

      <div className="ba-section">
        <div className="ba-section-title">Progress</div>
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
      </div>

      <div className="ba-section">
        <div className="ba-section-title">Update status</div>
        <div className="ba-status-seg">
          {STATUS_OPTIONS.map((s) => (
            <button key={s} className={`ba-status-btn s-${s.toLowerCase()}${s === status ? ' on' : ''}`}
              disabled={busy} onClick={() => void updateStatus(s)}>
              {s}
            </button>
          ))}
        </div>
      </div>

      {booking.invoice && (
        <div className="ba-section">
          <div className="ba-section-title">Invoice</div>
          <div className="ba-card ba-invoice">
            <div className="ba-invoice-row">
              <span className="ba-tile-l">Payment</span>
              <span className={`c-chip ${booking.invoice.paid ? 'c-chip-completed' : 'c-chip-pending'}`}>
                {booking.invoice.paid ? 'Paid' : (booking.invoice.status ?? 'Unpaid')}
              </span>
            </div>
            {!booking.invoice.paid && (
              <button className="c-btn c-btn-block" style={{ marginTop: 12 }} disabled={busy} onClick={() => void payInvoice()}>
                <Icons.Check size={16} /> Mark as Paid
              </button>
            )}
          </div>
        </div>
      )}

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
                    : <span className="ba-staff-assign">Assign</span>}
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
