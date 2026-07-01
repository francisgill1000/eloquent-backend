import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import {
  getBooking, setBookingStatus, reassignBooking, markInvoicePaid,
} from '@/lib/bookings';
import { getStaff } from '@/lib/shops';
import type { Booking, StaffMember } from '@/types';

const STATUS_OPTIONS = ['Booked', 'Completed', 'Cancelled'];

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
  const services = booking.services?.map((s) => s.title || s.name).filter(Boolean).join(', ') || 'Service';

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

      {error && <div className="c-error-box">{error}</div>}

      <div className="c-card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h2 style={{ margin: 0 }}>{name}</h2>
          <span className={`c-chip c-chip-${status.toLowerCase()}`}>{status}</span>
        </div>
        <div className="c-row"><span className="k">Service</span><span className="v">{services}</span></div>
        {booking.date && <div className="c-row"><span className="k">Date</span><span className="v">{booking.date}</span></div>}
        {booking.start_time && <div className="c-row"><span className="k">Time</span><span className="v">{booking.start_time}</span></div>}
        {booking.staff?.name && <div className="c-row"><span className="k">Staff</span><span className="v">{booking.staff.name}</span></div>}
        {booking.booking_reference && <div className="c-row"><span className="k">Reference</span><span className="v">{booking.booking_reference}</span></div>}
        <div className="c-row"><span className="k">Charges</span><span className="v">AED {booking.charges ?? 0}</span></div>
      </div>

      <div className="c-section-title">Update Status</div>
      <div style={{ display: 'flex', gap: 8, padding: '0 16px 12px' }}>
        {STATUS_OPTIONS.map((s) => (
          <button key={s} className={s === status ? 'c-btn' : 'c-btn-ghost'} style={{ flex: 1 }} disabled={busy} onClick={() => void updateStatus(s)}>
            {s}
          </button>
        ))}
      </div>

      {booking.invoice && (
        <>
          <div className="c-section-title">Invoice</div>
          <div className="c-card">
            <div className="c-row"><span className="k">Status</span><span className="v">{booking.invoice.paid ? 'Paid' : (booking.invoice.status ?? 'Unpaid')}</span></div>
            {!booking.invoice.paid && (
              <button className="c-btn c-btn-block" style={{ marginTop: 12 }} disabled={busy} onClick={() => void payInvoice()}>
                Mark as Paid
              </button>
            )}
          </div>
        </>
      )}

      {staff.length > 0 && (
        <>
          <div className="c-section-title">Assign Staff</div>
          <div className="c-card" style={{ padding: '0 16px' }}>
            {staff.map((m) => (
              <button key={m.id} className="c-list-row" style={{ width: '100%', background: 'none', border: 'none', borderTop: '1px solid var(--border-1)', cursor: 'pointer', textAlign: 'left' }} disabled={busy} onClick={() => void assign(m)}>
                <Icons.User size={16} />
                <span className="c-row-title" style={{ flex: 1 }}>{m.name}</span>
                {booking.staff?.id === m.id && <Icons.Check size={18} />}
              </button>
            ))}
          </div>
        </>
      )}
    </div></div>
  );
}
