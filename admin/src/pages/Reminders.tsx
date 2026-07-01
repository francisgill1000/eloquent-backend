import { useEffect, useState, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { AppBar } from '@/layout/AppBar';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getShopBookings, markReminderSent } from '@/lib/bookings';
import type { Booking } from '@/types';

function tomorrowISO(): string {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function tomorrowLabel(): string {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return d.toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long' });
}

function buildMessage(b: Booking, shopName?: string): string {
  const time = b.start_time ? String(b.start_time).slice(0, 5) : '';
  const customer = b.customer_name || b.customer?.name || 'there';
  const services = b.services?.length ? b.services.map((s) => s.title || s.name).join(', ') : null;
  const lines = [
    `Hi ${customer}!`,
    `Friendly reminder: your appointment at ${shopName || 'us'} is tomorrow at ${time || 'your booked time'}.`,
  ];
  if (services) lines.push(`Service: ${services}`);
  if (b.booking_reference) lines.push(`Booking ref: ${b.booking_reference}`);
  lines.push('See you then!');
  return lines.join('\n');
}

export default function Reminders() {
  const navigate = useNavigate();
  const { shop, logoutShop } = useShop();
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  const tomorrow = useMemo(() => tomorrowISO(), []);
  const label = useMemo(() => tomorrowLabel(), []);

  const fetchBookings = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true);
    setError('');
    try {
      const res = await getShopBookings(shop.id);
      setBookings(res.data.filter((b) => {
        const date = String(b.date ?? '').slice(0, 10);
        const status = String(b.status ?? '').toLowerCase();
        return date === tomorrow && (status === 'booked' || status === 'queued');
      }));
    } catch (e: unknown) {
      if ((e as { response?: { status?: number } })?.response?.status === 401) { logoutShop(); navigate('/login'); return; }
      setError('Could not load reminders.');
    } finally {
      setLoading(false);
    }
  }, [shop?.id, tomorrow, logoutShop, navigate]);

  useEffect(() => { void fetchBookings(); }, [fetchBookings]);

  const sendReminder = async (b: Booking) => {
    setBusyId(b.id);
    setError('');
    try {
      if (b.customer_whatsapp) {
        const num = String(b.customer_whatsapp).replace(/\D/g, '');
        const msg = encodeURIComponent(buildMessage(b, shop?.name));
        window.open(`https://wa.me/${num}?text=${msg}`, '_blank', 'noopener');
      }
      await markReminderSent(b.id);
      setBookings((arr) => arr.map((x) => (x.id === b.id ? { ...x, reminder_sent_at: new Date().toISOString() } : x)));
    } catch {
      setError('Could not mark as reminded.');
    } finally {
      setBusyId(null);
    }
  };

  const pendingCount = bookings.filter((b) => !b.reminder_sent_at && b.customer_whatsapp).length;

  return (
    <div className="m-screen">
      <AppBar title="Reminders" sub={`${label} · ${pendingCount} pending`} />
      <div className="m-scroll">
        {error && <div className="c-error-box">{error}</div>}

        {loading ? (
          <Spinner label="Loading reminders…" />
        ) : bookings.length > 0 ? (
          <div className="c-card" style={{ padding: '0 16px' }}>
            {bookings.map((b) => {
              const name = b.customer_name || b.customer?.name || 'Guest';
              const services = b.services?.map((s) => s.title || s.name).filter(Boolean).join(', ') || '—';
              const time = b.start_time ? String(b.start_time).slice(0, 5) : '—';
              const reminded = !!b.reminder_sent_at;
              const noPhone = !b.customer_whatsapp;
              return (
                <div key={b.id} className="c-list-row">
                  <div style={{ flex: 1 }}>
                    <div className="c-row-title">{name}</div>
                    <div className="c-row-sub">{services}</div>
                    <div className="c-row-sub"><Icons.Clock size={11} /> {time}{b.customer_whatsapp ? ` · ${b.customer_whatsapp}` : ''}</div>
                  </div>
                  {reminded ? (
                    <span className="c-chip c-chip-completed"><Icons.Check size={12} /> Sent</span>
                  ) : (
                    <button
                      className="c-btn"
                      style={{ padding: '8px 12px', fontSize: 12 }}
                      disabled={busyId === b.id || noPhone}
                      title={noPhone ? 'No phone number on file' : undefined}
                      onClick={() => void sendReminder(b)}
                    >
                      {busyId === b.id ? 'Sending…' : noPhone ? 'No phone' : 'Send'}
                    </button>
                  )}
                </div>
              );
            })}
          </div>
        ) : (
          <EmptyState title="Nothing tomorrow" subtitle="Bookings for tomorrow that need a reminder will appear here." />
        )}
      </div>
    </div>
  );
}
