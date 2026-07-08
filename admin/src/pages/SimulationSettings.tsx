import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getSimulation, saveSimulation, type SimScript, type SimTurn } from '@/lib/simulation';

const VOICES = ['nova', 'shimmer', 'coral', 'sage', 'alloy'];

/**
 * Editor for the shop's demo simulation — a scripted, dry-run voice booking used
 * to record marketing videos. Saving stores the script per shop; Play opens the
 * real Ask screen in sim mode. No real booking is ever created.
 */
export default function SimulationSettings() {
  const navigate = useNavigate();
  const [script, setScript] = useState<SimScript | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let alive = true;
    getSimulation()
      .then((s) => { if (alive) setScript(s); })
      .catch(() => { if (alive) setError('Could not load your simulation.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const patch = (p: Partial<SimScript>) => { setScript((s) => (s ? { ...s, ...p } : s)); setNotice(''); };
  const patchTurn = (i: number, t: Partial<SimTurn>) =>
    setScript((s) => (s ? { ...s, turns: s.turns.map((x, j) => (j === i ? { ...x, ...t } : x)) } : s));
  const addTurn = () =>
    setScript((s) => (s ? { ...s, turns: [...s.turns, { who: 'owner', text: '' }] } : s));
  const removeTurn = (i: number) =>
    setScript((s) => (s ? { ...s, turns: s.turns.filter((_, j) => j !== i) } : s));
  const patchBooking = (p: Partial<SimScript['booking']>) =>
    setScript((s) => (s ? { ...s, booking: { ...s.booking, ...p } } : s));

  const save = async () => {
    if (!script) return;
    setSaving(true); setError(''); setNotice('');
    try { setScript(await saveSimulation(script)); setNotice('Saved.'); }
    catch { setError('Could not save. Please try again.'); }
    finally { setSaving(false); }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading simulation…" /></div>;
  if (!script) return <div className="m-screen"><div className="m-scroll"><div className="c-error-box">{error || 'No simulation.'}</div></div></div>;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">Demo simulation</h1>
          <p className="c-page-sub">A scripted voice booking for recording demo videos. Playing it creates a real booking you can delete afterwards.</p>
        </div>
        <button className="c-icon-btn" aria-label="Back to settings" onClick={() => navigate('/settings')}><Icons.ChevronLeft size={18} /></button>
      </div>

      {error && <div className="c-error-box">{error}</div>}
      {notice && <div style={{ margin: '0 16px 12px', padding: 12, borderRadius: 'var(--r-md)', background: 'var(--mint-soft)', border: '1px solid var(--border-mint)', color: 'var(--mint-300)', fontSize: 13, textAlign: 'center' }}>{notice}</div>}

      <div style={{ padding: '0 16px 24px', display: 'flex', flexDirection: 'column', gap: 16 }}>
        {/* Conversation turns */}
        <div>
          <div className="c-field-label" style={{ margin: '0 4px 8px' }}>Conversation</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {script.turns.map((t, i) => (
              <div key={i} className="c-input-row c-input-area" style={{ flexDirection: 'column', alignItems: 'stretch', gap: 6, padding: 10 }}>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <select aria-label={`Turn ${i + 1} speaker`} value={t.who} onChange={(e) => patchTurn(i, { who: e.target.value as SimTurn['who'] })}
                    style={{ background: 'none', color: 'var(--text-1)', border: '1px solid var(--line, #333)', borderRadius: 8, padding: '4px 8px', font: 'inherit' }}>
                    <option value="owner">You</option>
                    <option value="assistant">Assistant</option>
                  </select>
                  <button className="c-icon-btn" aria-label={`Remove turn ${i + 1}`} onClick={() => removeTurn(i)} style={{ marginLeft: 'auto' }}><Icons.Trash size={15} /></button>
                </div>
                <textarea aria-label={`Turn ${i + 1} text`} rows={2} value={t.text} onChange={(e) => patchTurn(i, { text: e.target.value })}
                  style={{ background: 'none', border: 'none', outline: 'none', color: 'var(--text-1)', font: 'inherit', fontSize: 13.5, resize: 'vertical' }} />
              </div>
            ))}
          </div>
          <button className="c-btn-ghost" style={{ width: '100%', marginTop: 10 }} onClick={addTurn}><Icons.Plus size={15} /> Add line</button>
        </div>

        {/* Booking preview fields */}
        <div>
          <div className="c-field-label" style={{ margin: '0 4px 8px' }}>Booking shown at the end</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
            {([
              ['customer_name', 'Customer'], ['customer_phone', 'Phone (fake)'],
              ['service', 'Service'], ['price', 'Price'],
              ['date', 'Date'], ['start_time', 'Start'], ['end_time', 'End'], ['staff_name', 'Staff'],
            ] as [keyof SimScript['booking'], string][]).map(([k, label]) => (
              <label key={k} style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--text-4)' }}>
                {label}
                <input value={script.booking[k]} onChange={(e) => patchBooking({ [k]: e.target.value })}
                  style={{ background: 'none', border: '1px solid var(--line, #333)', borderRadius: 8, color: 'var(--text-1)', padding: '6px 8px', font: 'inherit' }} />
              </label>
            ))}
          </div>
        </div>

        {/* Voices + pacing */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--text-4)' }}>
            Your voice
            <select value={script.voices.owner} onChange={(e) => patch({ voices: { ...script.voices, owner: e.target.value } })}
              style={{ background: 'none', color: 'var(--text-1)', border: '1px solid var(--line, #333)', borderRadius: 8, padding: '6px 8px', font: 'inherit' }}>
              {VOICES.map((v) => <option key={v} value={v}>{v}</option>)}
            </select>
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--text-4)' }}>
            Assistant voice
            <select value={script.voices.assistant} onChange={(e) => patch({ voices: { ...script.voices, assistant: e.target.value } })}
              style={{ background: 'none', color: 'var(--text-1)', border: '1px solid var(--line, #333)', borderRadius: 8, padding: '6px 8px', font: 'inherit' }}>
              {VOICES.map((v) => <option key={v} value={v}>{v}</option>)}
            </select>
          </label>
        </div>

        <div style={{ display: 'flex', gap: 10 }}>
          <button className="c-btn" style={{ flex: 1 }} disabled={saving} onClick={() => void save()}>{saving ? 'Saving…' : 'Save'}</button>
          <button className="c-btn-ghost" style={{ flex: 1, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8 }} onClick={() => navigate('/ask?sim=1')}>
            <Icons.Mic size={16} /> Play
          </button>
        </div>
      </div>
    </div></div>
  );
}
