import { useEffect, useState, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getCustomer, updateCustomer, type CustomerDetail as Detail } from '@/lib/customers';
import '@/styles/customers.css';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function fmtDate(s: string | null): string {
  if (!s) return '—';
  const d = new Date(String(s).slice(0, 10));
  if (isNaN(d.getTime())) return '—';
  return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}
const fmtMoney = (n: number) => `AED ${Number(n || 0).toLocaleString()}`;

export default function CustomerDetail() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { shop } = useShop();

  const [c, setC] = useState<Detail | null>(null);
  const [notes, setNotes] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [savedAt, setSavedAt] = useState(false);
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
      const updated = await updateCustomer(shop.id, c.id, { name: val.trim() });
      setC(updated);
    } catch { setError('Could not rename.'); }
  };

  const saveNotes = async () => {
    if (!shop?.id || !c) return;
    setSaving(true); setError(''); setSavedAt(false);
    try {
      const updated = await updateCustomer(shop.id, c.id, { notes: notes.trim() || null });
      setC(updated);
      setSavedAt(true);
      setTimeout(() => setSavedAt(false), 2000);
    } catch { setError('Could not save notes.'); } finally { setSaving(false); }
  };

  if (loading) return <div className="m-screen"><div className="m-scroll"><Spinner label="Loading customer…" /></div></div>;

  return (
    <div className="m-screen c-customers"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

      {error && <div className="c-error-box">{error}</div>}

      {c && (
        <>
          <div className="cust-hero">
            <div className="cust-avatar">{(c.name || '?').charAt(0).toUpperCase()}</div>
            <div className="cust-hero-body">
              <div className="cust-hero-name">
                {c.name || 'Guest'}
                <button className="c-icon-btn" aria-label="Rename" onClick={() => void rename()}><Icons.Edit size={14} /></button>
              </div>
              <a className="cust-hero-num" href={c.whatsapp ? `tel:${c.whatsapp}` : undefined}>
                <Icons.Phone size={13} /> {c.whatsapp || 'No number'}
              </a>
            </div>
          </div>

          <div className="c-mini-stats" style={{ margin: '0 0 16px' }}>
            <div className="c-mini"><span className="v">{c.bookings_count}</span><span className="k">Bookings</span></div>
            <div className="c-mini"><span className="v">{fmtMoney(c.total_spent)}</span><span className="k">Spent</span></div>
            <div className="c-mini"><span className="v" style={{ fontSize: 13 }}>{fmtDate(c.last_visit_date)}</span><span className="k">Last visit</span></div>
          </div>

          <label className="c-field-label">Notes</label>
          <div className="c-input-row" style={{ alignItems: 'flex-start' }}>
            <textarea rows={4} placeholder="Preferences, allergies, reminders…" value={notes}
              onChange={(e) => setNotes(e.target.value)}
              style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', resize: 'vertical', font: 'inherit' }} />
          </div>
          <button className="c-btn c-btn-block" style={{ marginTop: 10 }} disabled={saving} onClick={() => void saveNotes()}>
            {saving ? 'Saving…' : savedAt ? 'Saved ✓' : 'Save notes'}
          </button>
        </>
      )}
    </div></div>
  );
}
