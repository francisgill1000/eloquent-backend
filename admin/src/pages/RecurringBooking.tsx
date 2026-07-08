import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { listCatalogs } from '@/lib/catalogs';
import { bookRecurring } from '@/lib/bookings';
import type { Service, Booking } from '@/types';

export default function RecurringBooking() {
  const navigate = useNavigate();
  const { shop } = useShop();

  const [services, setServices] = useState<Service[]>([]);
  const [serviceId, setServiceId] = useState<string>('');
  const [customerName, setCustomerName] = useState('');
  const [customerWhatsapp, setCustomerWhatsapp] = useState('');
  const [date, setDate] = useState('');
  const [time, setTime] = useState('10:00');
  const [frequency, setFrequency] = useState<'weekly' | 'biweekly' | 'monthly'>('weekly');
  const [occurrences, setOccurrences] = useState('4');

  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState<{ created: Booking[]; skipped: Array<{ date: string; reason: string }> } | null>(null);

  useEffect(() => {
    listCatalogs()
      .then((list) => { setServices(list); if (list[0]) setServiceId(String(list[0].id)); })
      .catch(() => setError('Could not load services.'))
      .finally(() => setLoading(false));
  }, []);

  const submit = async () => {
    if (!shop?.id) return;
    const svc = services.find((s) => String(s.id) === serviceId);
    if (!svc) { setError('Please choose a service.'); return; }
    if (!date) { setError('Please choose a start date.'); return; }
    if (!customerWhatsapp.trim()) { setError('Please enter the customer contact number.'); return; }
    const n = Number(occurrences);
    if (!Number.isInteger(n) || n < 2 || n > 52) { setError('Occurrences must be between 2 and 52.'); return; }

    setSubmitting(true);
    setError('');
    setResult(null);
    try {
      const res = await bookRecurring(shop.id, {
        date,
        start_time: time,
        services: [{ id: svc.id, title: svc.title ?? svc.name, price: svc.price }],
        charges: Number(svc.price ?? 0),
        customer_name: customerName || undefined,
        customer_whatsapp: customerWhatsapp.trim(),
        frequency,
        occurrences: n,
      });
      setResult({ created: res.created, skipped: res.skipped });
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg || 'Could not create the recurring series.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading…" /></div>;

  const inputStyle = { width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', font: 'inherit' } as const;

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate('/bookings')}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Recurring booking</h1>
        <p className="c-page-sub">Set up a regular — e.g. every week at the same time. Each date is booked (or queued if the slot is full).</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {result ? (
        <div className="c-set-grid" style={{ gap: 10 }}>
          <div className="c-error-box" style={{ background: 'rgba(16,185,129,.12)', color: 'var(--mint-400, #10B981)', borderColor: 'transparent' }}>
            Created {result.created.length} booking{result.created.length !== 1 ? 's' : ''}.
          </div>
          {result.created.map((b) => (
            <div key={b.id} className="c-set-link" style={{ cursor: 'default' }}>
              <span className="c-set-body">
                <span className="c-set-label">{b.date} · {String(b.start_time).slice(0, 5)}</span>
                <span className="c-set-sub">{b.booking_reference} · {b.status}</span>
              </span>
            </div>
          ))}
          {result.skipped.map((s, i) => (
            <div key={i} className="c-set-link" style={{ cursor: 'default', opacity: 0.7 }}>
              <span className="c-set-body">
                <span className="c-set-label">{s.date} — skipped</span>
                <span className="c-set-sub">{s.reason}</span>
              </span>
            </div>
          ))}
          <button className="c-btn c-btn-block" onClick={() => navigate('/bookings')}>Done</button>
        </div>
      ) : (
        <div className="svc-form">
          <label className="c-field-label">Service</label>
          <div className="c-input-row">
            <select value={serviceId} onChange={(e) => setServiceId(e.target.value)} style={inputStyle}>
              {services.map((s) => <option key={s.id} value={String(s.id)}>{s.title || s.name} — AED {s.price}</option>)}
            </select>
          </div>

          <label className="c-field-label">Customer name</label>
          <div className="c-input-row"><input type="text" value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="e.g. Riya" /></div>

          <label className="c-field-label">Customer contact number *</label>
          <div className="c-input-row"><input type="tel" required value={customerWhatsapp} onChange={(e) => setCustomerWhatsapp(e.target.value)} placeholder="9715XXXXXXXX" /></div>

          <label className="c-field-label">Start date</label>
          <div className="c-input-row"><input type="date" value={date} onChange={(e) => setDate(e.target.value)} style={inputStyle} /></div>

          <label className="c-field-label">Time</label>
          <div className="c-input-row"><input type="time" value={time} onChange={(e) => setTime(e.target.value)} style={inputStyle} /></div>

          <label className="c-field-label">Repeats</label>
          <div className="c-input-row">
            <select value={frequency} onChange={(e) => setFrequency(e.target.value as typeof frequency)} style={inputStyle}>
              <option value="weekly">Weekly</option>
              <option value="biweekly">Every 2 weeks</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>

          <label className="c-field-label">Number of appointments (2–52)</label>
          <div className="c-input-row"><input type="number" min="2" max="52" value={occurrences} onChange={(e) => setOccurrences(e.target.value)} /></div>

          <button className="c-btn c-btn-block" disabled={submitting} onClick={() => void submit()}>
            {submitting ? 'Creating…' : 'Create recurring booking'}
          </button>
        </div>
      )}
    </div></div>
  );
}
