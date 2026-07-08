import { useEffect, useState, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getCustomer, updateCustomer, type CustomerDetail as Detail, type CustomerBooking } from '@/lib/customers';
import '@/styles/customers.css';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function parse(s: string | null): Date | null {
  if (!s) return null;
  const d = new Date(String(s).slice(0, 10));
  return isNaN(d.getTime()) ? null : d;
}
function fmtDate(s: string | null): string {
  const d = parse(s);
  return d ? `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}` : '—';
}
const fmtMoney = (n: number) => `AED ${Number(n || 0).toLocaleString()}`;
function svcLabel(b: CustomerBooking): string {
  return b.services?.map((s) => s.title || s.name).filter(Boolean).join(', ') || 'Service';
}

export default function CustomerDetail() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { shop } = useShop();

  const [c, setC] = useState<Detail | null>(null);
  const [notes, setNotes] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    if (!shop?.id || !id) return;
    setLoading(true); setError('');
    try {
      const data = await getCustomer(shop.id, Number(id));
      setC(data);
      setNotes(data.notes ?? '');
    } catch {
      setError('Could not load this customer.');
    } finally {
      setLoading(false);
    }
  }, [shop?.id, id]);

  useEffect(() => { void load(); }, [load]);

  const rename = async () => {
    if (!shop?.id || !c) return;
    const val = window.prompt('Customer name', c.name ?? '');
    if (val == null || val.trim() === (c.name ?? '')) return;
    try {
      await updateCustomer(shop.id, c.id, { name: val.trim() });
      setC((prev) => (prev ? { ...prev, name: val.trim() } : prev));
    } catch { setError('Could not rename.'); }
  };

  const saveNotes = async () => {
    if (!shop?.id || !c) return;
    setSaving(true); setError(''); setSaved(false);
    try {
      await updateCustomer(shop.id, c.id, { notes: notes.trim() || null });
      setC((prev) => (prev ? { ...prev, notes: notes.trim() || null } : prev));
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
    } catch { setError('Could not save notes.'); } finally { setSaving(false); }
  };

  if (loading) return <div className="m-screen"><div className="m-scroll"><Spinner label="Loading customer…" /></div></div>;

  return (
    <div className="m-screen c-customers"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

      {error && <div className="c-error-box">{error}</div>}

      {c && (
        <>
          {/* Hero */}
          <div className="cust-hero">
            <div className="cust-avatar">{(c.name || '?').charAt(0).toUpperCase()}</div>
            <div className="cust-hero-body">
              <div className="cust-hero-name">
                {c.name || 'Guest'}
                <button className="c-icon-btn" aria-label="Rename" onClick={() => void rename()}><Icons.Edit size={14} /></button>
              </div>
              <div className="cust-hero-meta">
                <a className="cust-hero-num" href={c.whatsapp ? `tel:${c.whatsapp}` : undefined}>
                  <Icons.Phone size={13} /> {c.whatsapp || 'No number'}
                </a>
                {c.first_visit_date && (
                  <span className="cust-hero-since"><Icons.Calendar size={13} /> Customer since {fmtDate(c.first_visit_date)}</span>
                )}
              </div>
            </div>
          </div>

          {/* Stat grid */}
          <div className="cust-stats">
            <div className="cust-stat"><span className="cust-stat-label">Total bookings</span><span className="cust-stat-value">{c.bookings_count}</span></div>
            <div className="cust-stat"><span className="cust-stat-label">Total spent</span><span className="cust-stat-value">{fmtMoney(c.total_spent)}</span></div>
            <div className="cust-stat"><span className="cust-stat-label">Avg / visit</span><span className="cust-stat-value">{fmtMoney(c.avg_spent)}</span></div>
            <div className="cust-stat"><span className="cust-stat-label">Last visit</span><span className="cust-stat-value sm">{fmtDate(c.last_visit_date)}</span></div>
          </div>

          {/* Reliability breakdown */}
          <div className="cust-rel">
            <span className="cust-rel-pill"><span className="cust-rel-dot" style={{ background: 'var(--mint-300)' }} /><b>{c.completed_count}</b> completed</span>
            <span className="cust-rel-pill"><span className="cust-rel-dot" style={{ background: 'var(--info)' }} /><b>{c.upcoming_count}</b> upcoming</span>
            <span className="cust-rel-pill"><span className="cust-rel-dot" style={{ background: 'var(--warn)' }} /><b>{c.cancelled_count}</b> cancelled</span>
            <span className="cust-rel-pill"><span className="cust-rel-dot" style={{ background: 'var(--danger)' }} /><b>{c.no_show_count}</b> no-show</span>
          </div>

          {/* Booking history */}
          <div className="cust-section-title">Booking history</div>
          {c.bookings.length === 0 ? (
            <div className="ins-empty" style={{ marginBottom: 20 }}><span className="ins-empty-txt">No bookings yet.</span></div>
          ) : (
            <div className="cust-bk-list">
              {c.bookings.map((b) => {
                const d = parse(b.date);
                return (
                  <button key={b.id} className="cust-bk-row" onClick={() => navigate(`/booking/${b.id}`)}>
                    <span className="cust-bk-when">
                      <span className="cust-bk-day">{d ? d.getDate() : '—'}</span>
                      <span className="cust-bk-mon">{d ? MONTHS[d.getMonth()] : ''}</span>
                    </span>
                    <span className="cust-bk-body">
                      <span className="cust-bk-svc">{svcLabel(b)}</span>
                      <span className="cust-bk-ref">{b.reference || ''}{b.start_time ? ` · ${b.start_time}` : ''}</span>
                    </span>
                    <span className="cust-bk-right">
                      <span className={`cust-chip ${b.status}`}>{b.status.replace('_', '-')}</span>
                      <span className="cust-bk-amount">{fmtMoney(b.charges)}</span>
                    </span>
                  </button>
                );
              })}
            </div>
          )}

          {/* Notes */}
          <div className="cust-section-title">Notes</div>
          <div className="c-input-row" style={{ alignItems: 'flex-start' }}>
            <textarea rows={4} placeholder="Preferences, allergies, reminders…" value={notes}
              onChange={(e) => setNotes(e.target.value)}
              style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', resize: 'vertical', font: 'inherit' }} />
          </div>
          <button className="c-btn c-btn-block" style={{ marginTop: 10 }} disabled={saving} onClick={() => void saveNotes()}>
            {saving ? 'Saving…' : saved ? 'Saved ✓' : 'Save notes'}
          </button>
        </>
      )}
    </div></div>
  );
}
