import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getShop, updateShop } from '@/lib/shops';

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Backend stores day_of_week as PHP date('w') / Carbon dayOfWeek: Sun=0..Sat=6.
// DAYS is Monday-first, so map index 0(Mon)->1 … 5(Sat)->6 … 6(Sun)->0.
const dowFor = (i: number): number => (i + 1) % 7;

const TIME_OPTIONS: string[] = [];
for (let h = 0; h < 24; h++) {
  for (let m = 0; m < 60; m += 30) {
    TIME_OPTIONS.push(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`);
  }
}

type Row = { day: string; day_of_week: number; is_open: boolean; start_time: string; end_time: string };

function buildRows(workingHours: Array<{ day_of_week?: number; day?: string; start_time?: string; end_time?: string }>): Row[] {
  return DAYS.map((day, i) => {
    const dayNum = dowFor(i);
    const found = workingHours.find((d) => d.day_of_week === dayNum || d.day?.toLowerCase() === day.toLowerCase());
    return found
      ? { day, day_of_week: dayNum, is_open: true, start_time: found.start_time || '09:00', end_time: found.end_time || '18:00' }
      : { day, day_of_week: dayNum, is_open: false, start_time: '09:00', end_time: '18:00' };
  });
}

export default function WorkingHours() {
  const navigate = useNavigate();
  const { shop, token, loginShop } = useShop();
  const [rows, setRows] = useState<Row[]>(buildRows([]));
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    // Seed from the cached shop if it happens to carry hours…
    const seed = (shop?.working_hours as Array<{ day_of_week?: number; day?: string; start_time?: string; end_time?: string }>) || [];
    if (seed.length > 0) setRows(buildRows(seed));
    // …but the login payload omits working_hours, so always fetch the full shop.
    if (!shop?.id) return;
    let alive = true;
    getShop(shop.id)
      .then((full) => {
        if (!alive) return;
        const data = (full?.working_hours as Array<{ day_of_week?: number; day?: string; start_time?: string; end_time?: string }>) || [];
        setRows(buildRows(data));
      })
      .catch(() => undefined);
    return () => { alive = false; };
  }, [shop?.id]);

  const updateDay = (index: number, key: keyof Row, value: Row[keyof Row]) => {
    setRows((prev) => prev.map((r, i) => (i === index ? { ...r, [key]: value } : r)));
  };

  const handleSave = async () => {
    if (!shop?.id) return;
    setSaving(true);
    setError('');
    setMessage('');
    try {
      const working_hours = rows
        .filter((r) => r.is_open)
        .map((r) => ({ day_of_week: r.day_of_week, start_time: r.start_time, end_time: r.end_time, slot_duration: 30 }));
      const updated = await updateShop(shop.id, { working_hours } as { working_hours: typeof working_hours });
      if (updated && token) loginShop(updated, token);
      setMessage('Working hours updated.');
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Failed to save working hours.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="m-screen c-wh"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Working Hours</h1>
        <p className="c-page-sub">Set your open and close times for each day.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}
      {message && <div className="c-card" style={{ color: 'var(--mint-300)' }}>{message}</div>}

      <div className="wh-grid">
      {rows.map((r, i) => (
        <div key={r.day} className="c-wh-day">
          <div className="c-wh-head">
            <span className={`c-wh-name${r.is_open ? '' : ' closed'}`}>{r.day}</span>
            <label className="c-wh-toggle">
              <span className={`c-wh-state ${r.is_open ? 'on' : 'off'}`}>{r.is_open ? 'Open' : 'Closed'}</span>
              <span className="c-switch">
                <input type="checkbox" checked={r.is_open} onChange={(e) => updateDay(i, 'is_open', e.target.checked)} aria-label={`${r.day} open`} />
                <span className="track" />
              </span>
            </label>
          </div>
          {r.is_open && (
            <div className="c-wh-times">
              <div className="c-wh-field">
                <label htmlFor={`${r.day}-open`}>Opens</label>
                <select id={`${r.day}-open`} className="c-wh-select" value={r.start_time} onChange={(e) => updateDay(i, 'start_time', e.target.value)}>
                  {TIME_OPTIONS.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </div>
              <span className="c-wh-arrow"><Icons.ArrowRight size={18} /></span>
              <div className="c-wh-field">
                <label htmlFor={`${r.day}-close`}>Closes</label>
                <select id={`${r.day}-close`} className="c-wh-select" value={r.end_time} onChange={(e) => updateDay(i, 'end_time', e.target.value)}>
                  {TIME_OPTIONS.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </div>
            </div>
          )}
        </div>
      ))}
      </div>

      <div className="wh-save" style={{ padding: '8px 16px 24px' }}>
        <button className="c-btn c-btn-block" disabled={saving} onClick={() => void handleSave()}>
          {saving ? 'Saving…' : 'Save Working Hours'}
        </button>
      </div>
    </div></div>
  );
}
