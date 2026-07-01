import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import type { Booking } from '@/types';
import { AppBar } from '@/layout/AppBar';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { Icons } from '@/components/Icons';

function statusClass(status: string): string {
  const s = status.toLowerCase();
  if (s === 'completed') return 'completed';
  if (s === 'cancelled') return 'cancelled';
  return 'booked';
}

function dateParts(date?: string): { day: string; mon: string } {
  if (!date) return { day: '--', mon: '---' };
  const d = new Date(`${date}T00:00:00`);
  return { day: String(d.getDate()), mon: d.toLocaleString('en-US', { month: 'short' }) };
}

export default function Bookings() {
  const navigate = useNavigate();
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/bookings')
      .then((res) => setBookings(Array.isArray(res.data) ? res.data : res.data?.data ?? []))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="m-screen">
      <AppBar title="My Bookings" actions={<WhatsAppButton />} />
      <div className="m-scroll">
        {loading && <Spinner />}
        {!loading && bookings.length === 0 && (
          <EmptyState
            icon={<Icons.Calendar size={32} />}
            title="No bookings yet"
            subtitle="Browse businesses and make your first booking."
            action={<button className="c-btn-ghost" onClick={() => navigate('/explore')}>Explore</button>}
          />
        )}
        {!loading && bookings.map((b) => {
          const { day, mon } = dateParts(b.date);
          const status = b.status || 'Booked';
          const services = b.services?.map((s) => s.title || s.name).filter(Boolean).join(', ') || 'Service';
          return (
            <button key={b.id} className="c-shop-card" onClick={() => navigate(`/booking/${b.id}`)}>
              <span className="thumb" style={{ width: 56, height: 56, display: 'grid', placeItems: 'center', color: 'var(--mint-300)', background: 'var(--mint-soft)' }}>
                <span style={{ textAlign: 'center', lineHeight: 1 }}>
                  <span style={{ display: 'block', fontSize: 18, fontWeight: 800 }}>{day}</span>
                  <span style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase' }}>{mon}</span>
                </span>
              </span>
              <div className="body">
                <div className="top">
                  <span className="c-shop-name" style={{ marginTop: 0 }}>{b.shop?.name || 'Shop'}</span>
                  <span className={`c-status ${statusClass(status)}`}>{status}</span>
                </div>
                <span className="c-shop-meta">{services}</span>
                {b.start_time && <span className="c-hours" style={{ display: 'inline-flex', alignItems: 'center', gap: 4, marginTop: 4 }}><Icons.Clock size={13} /> {b.start_time}</span>}
                <span style={{ fontWeight: 700, marginTop: 4 }}>AED {b.charges || 0}</span>
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
}
