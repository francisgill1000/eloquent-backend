import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getStaff } from '@/lib/shops';
import {
  getSchedule, setSchedule, getTimeOff, addTimeOff, deleteTimeOff,
  type TimeOff,
} from '@/lib/staffAvailability';

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

type Row = { enabled: boolean; start: string; end: string };
const DEFAULT_ROW: Row = { enabled: false, start: '09:00', end: '17:00' };

function hhmm(t: string | null | undefined): string {
  return t ? String(t).slice(0, 5) : '';
}

export default function StaffAvailability() {
  const { id } = useParams<{ id: string }>();
  const staffId = Number(id);
  const navigate = useNavigate();
  const { shop } = useShop();

  const [name, setName] = useState('');
  const [rows, setRows] = useState<Row[]>(DAYS.map(() => ({ ...DEFAULT_ROW })));
  const [timeOff, setTimeOff] = useState<TimeOff[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState('');

  // New time-off form
  const [offDate, setOffDate] = useState('');
  const [offStart, setOffStart] = useState('');
  const [offEnd, setOffEnd] = useState('');
  const [offReason, setOffReason] = useState('');
  const [addingOff, setAddingOff] = useState(false);

  const load = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true);
    try {
      const [staffList, sched, off] = await Promise.all([
        getStaff(shop.id),
        getSchedule(shop.id, staffId),
        getTimeOff(shop.id, staffId),
      ]);
      setName(staffList.find((s) => s.id === staffId)?.name ?? `Staff #${staffId}`);
      const next = DAYS.map(() => ({ ...DEFAULT_ROW }));
      for (const s of sched) {
        if (s.day_of_week >= 0 && s.day_of_week <= 6) {
          next[s.day_of_week] = { enabled: true, start: hhmm(s.start_time), end: hhmm(s.end_time) };
        }
      }
      setRows(next);
      setTimeOff(off);
    } catch {
      setError('Could not load availability.');
    } finally {
      setLoading(false);
    }
  }, [shop?.id, staffId]);

  useEffect(() => { void load(); }, [load]);

  const setRow = (day: number, patch: Partial<Row>) => {
    setRows((prev) => prev.map((r, i) => (i === day ? { ...r, ...patch } : r)));
    setSaved(false);
  };

  const saveSchedule = async () => {
    if (!shop?.id) return;
    for (const [i, r] of rows.entries()) {
      if (r.enabled && r.start >= r.end) {
        setError(`${DAYS[i]}: start time must be before end time.`);
        return;
      }
    }
    setSaving(true);
    setError('');
    try {
      const payload = rows
        .map((r, day) => ({ day_of_week: day, start_time: r.start, end_time: r.end, enabled: r.enabled }))
        .filter((r) => r.enabled)
        .map(({ enabled, ...rest }) => { void enabled; return rest; });
      await setSchedule(shop.id, staffId, payload);
      setSaved(true);
    } catch {
      setError('Could not save schedule.');
    } finally {
      setSaving(false);
    }
  };

  const handleAddTimeOff = async () => {
    if (!shop?.id || !offDate) return;
    setAddingOff(true);
    setError('');
    try {
      const created = await addTimeOff(shop.id, staffId, {
        date: offDate,
        start_time: offStart || null,
        end_time: offEnd || null,
        reason: offReason || null,
      });
      setTimeOff((prev) => [...prev, created]);
      setOffDate(''); setOffStart(''); setOffEnd(''); setOffReason('');
    } catch {
      setError('Could not add time off (check the times).');
    } finally {
      setAddingOff(false);
    }
  };

  const removeTimeOff = async (offId: number) => {
    if (!shop?.id) return;
    try {
      await deleteTimeOff(shop.id, staffId, offId);
      setTimeOff((prev) => prev.filter((t) => t.id !== offId));
    } catch {
      setError('Could not remove time off.');
    }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading availability…" /></div>;

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate('/staff')}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">{name} — availability</h1>
        <p className="c-page-sub">Weekly shifts and time off. A staff member with no shifts follows the shop’s hours.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      <h3 className="c-set-sub" style={{ margin: '4px 0 8px' }}>Weekly schedule</h3>
      <div className="c-set-grid" style={{ gap: 8 }}>
        {rows.map((r, day) => (
          <div key={day} className="c-set-link" style={{ gap: 10, cursor: 'default' }}>
            <span
              role="button" tabIndex={0} aria-pressed={r.enabled}
              onClick={() => setRow(day, { enabled: !r.enabled })}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setRow(day, { enabled: !r.enabled }); } }}
              style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', minWidth: 88 }}>
              <span className={`c-toggle ${r.enabled ? 'on' : ''}`}><span className="c-toggle-knob" /></span>
              <span className="c-set-label">{DAYS[day]}</span>
            </span>
            {r.enabled && (
              <span style={{ display: 'flex', alignItems: 'center', gap: 6, marginLeft: 'auto' }}>
                <input type="time" value={r.start} onChange={(e) => setRow(day, { start: e.target.value })}
                  style={{ background: 'none', border: '1px solid var(--line, #333)', borderRadius: 8, color: 'var(--text-1)', padding: '4px 6px', font: 'inherit' }} />
                <span>–</span>
                <input type="time" value={r.end} onChange={(e) => setRow(day, { end: e.target.value })}
                  style={{ background: 'none', border: '1px solid var(--line, #333)', borderRadius: 8, color: 'var(--text-1)', padding: '4px 6px', font: 'inherit' }} />
              </span>
            )}
          </div>
        ))}
      </div>
      <button className="c-btn c-btn-block" disabled={saving} onClick={() => void saveSchedule()} style={{ marginTop: 10 }}>
        {saving ? 'Saving…' : saved ? 'Saved ✓' : 'Save schedule'}
      </button>

      <h3 className="c-set-sub" style={{ margin: '22px 0 8px' }}>Time off</h3>
      <div className="c-staff-add" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div className="c-input-row" style={{ maxWidth: 160 }}>
          <input type="date" value={offDate} onChange={(e) => setOffDate(e.target.value)} aria-label="Date" />
        </div>
        <div className="c-input-row" style={{ maxWidth: 120 }}>
          <input type="time" value={offStart} onChange={(e) => setOffStart(e.target.value)} aria-label="From (optional)" placeholder="From" />
        </div>
        <div className="c-input-row" style={{ maxWidth: 120 }}>
          <input type="time" value={offEnd} onChange={(e) => setOffEnd(e.target.value)} aria-label="To (optional)" placeholder="To" />
        </div>
        <div className="c-input-row" style={{ flex: 1, minWidth: 120 }}>
          <input type="text" value={offReason} onChange={(e) => setOffReason(e.target.value)} placeholder="Reason (optional)" />
        </div>
        <button className="c-btn" style={{ height: 54, padding: '0 16px' }} disabled={addingOff || !offDate} onClick={() => void handleAddTimeOff()}>
          <Icons.Plus size={16} /> {addingOff ? 'Adding…' : 'Add'}
        </button>
      </div>
      <p className="c-page-sub" style={{ marginTop: 4 }}>Leave the times blank for a full day off.</p>

      {timeOff.length > 0 && (
        <div className="c-set-grid" style={{ gap: 8, marginTop: 8 }}>
          {timeOff.map((t) => (
            <div key={t.id} className="c-set-link" style={{ cursor: 'default' }}>
              <span className="c-set-body">
                <span className="c-set-label">{t.date}{t.start_time ? ` · ${hhmm(t.start_time)}–${hhmm(t.end_time)}` : ' · Full day'}</span>
                {t.reason && <span className="c-set-sub">{t.reason}</span>}
              </span>
              <button className="c-icon-btn" aria-label="Remove" onClick={() => void removeTimeOff(t.id)}><Icons.Trash size={15} /></button>
            </div>
          ))}
        </div>
      )}
    </div></div>
  );
}
