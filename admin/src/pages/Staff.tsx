import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getStaff, addStaff, updateStaff } from '@/lib/shops';
import type { StaffMember } from '@/types';

export default function Staff() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const [staff, setStaff] = useState<StaffMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [newName, setNewName] = useState('');
  const [adding, setAdding] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [view, setView] = useState<'cards' | 'list'>(
    () => (localStorage.getItem('staff_view') === 'list' ? 'list' : 'cards'),
  );
  useEffect(() => { localStorage.setItem('staff_view', view); }, [view]);

  const fetchStaff = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true);
    try {
      setStaff(await getStaff(shop.id));
    } catch {
      setError('Could not load staff.');
    } finally {
      setLoading(false);
    }
  }, [shop?.id]);

  useEffect(() => { void fetchStaff(); }, [fetchStaff]);

  const handleAdd = async () => {
    if (!newName.trim() || !shop?.id) return;
    setAdding(true);
    setError('');
    try {
      const member = await addStaff(shop.id, newName.trim());
      setStaff((prev) => [...prev, member]);
      setNewName('');
    } catch {
      setError('Failed to add staff.');
    } finally {
      setAdding(false);
    }
  };

  const rename = async (member: StaffMember) => {
    if (!shop?.id) return;
    const val = window.prompt(`Update name for ${member.name}`, member.name);
    if (!val?.trim() || val.trim() === member.name) return;
    setBusyId(member.id);
    try {
      const updated = await updateStaff(shop.id, member.id, { name: val.trim() });
      setStaff((prev) => prev.map((s) => (s.id === member.id ? updated : s)));
    } catch {
      setError('Could not rename staff.');
    } finally {
      setBusyId(null);
    }
  };

  const toggleActive = async (member: StaffMember) => {
    if (!shop?.id) return;
    setBusyId(member.id);
    try {
      const updated = await updateStaff(shop.id, member.id, { is_active: !member.is_active });
      setStaff((prev) => prev.map((s) => (s.id === member.id ? updated : s)));
    } catch {
      setError('Could not update staff.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="m-screen c-staff"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Staff</h1>
        <p className="c-page-sub">Add the people who handle bookings.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      <div className="c-staff-add">
        <div className="c-input-row">
          <input type="text" placeholder="New staff name" value={newName}
            onChange={(e) => setNewName(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') void handleAdd(); }} />
        </div>
        <button className="c-btn c-btn-block" style={{ width: 'auto', height: 54, padding: '0 18px' }} disabled={adding || !newName.trim()} onClick={() => void handleAdd()}>
          <Icons.Plus size={16} /> {adding ? 'Adding…' : 'Add'}
        </button>
      </div>

      {loading ? (
        <Spinner label="Loading staff…" />
      ) : staff.length === 0 ? (
        <EmptyState title="No staff yet" subtitle="Add your team members to assign them to bookings." />
      ) : (
        <>
          <div className="c-listhead">
            <span className="c-dt-sub">{staff.length} member{staff.length !== 1 ? 's' : ''}</span>
            <div className="c-viewtog" role="group" aria-label="View">
              <button className={`c-viewbtn${view === 'cards' ? ' on' : ''}`} aria-pressed={view === 'cards'}
                onClick={() => setView('cards')} aria-label="Card view" title="Cards"><Icons.Grid size={15} /></button>
              <button className={`c-viewbtn${view === 'list' ? ' on' : ''}`} aria-pressed={view === 'list'}
                onClick={() => setView('list')} aria-label="List view" title="List"><Icons.List size={15} /></button>
            </div>
          </div>

          {view === 'list' ? (
            <div className="c-dtable-wrap">
              <table className="c-dtable">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th style={{ width: 110 }}>Status</th>
                    <th className="ta-r" style={{ width: 120 }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {staff.map((m) => {
                    const active = m.is_active !== false;
                    return (
                      <tr key={m.id}>
                        <td className="c-dt-namecell"><span className="c-dt-name">{m.name}</span></td>
                        <td>
                          <span className={active ? 'c-chip c-chip-completed' : 'c-chip c-chip-cancelled'}>
                            {active ? 'Active' : 'Inactive'}
                          </span>
                        </td>
                        <td className="c-dt-act">
                          <button className="c-icon-btn" aria-label="Rename" disabled={busyId === m.id} onClick={() => void rename(m)}>
                            <Icons.Edit size={15} />
                          </button>
                          <button className={`c-icon-btn${active ? ' on' : ''}`} aria-label={active ? 'Disable' : 'Enable'} disabled={busyId === m.id} onClick={() => void toggleActive(m)}>
                            <Icons.Power size={15} />
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="staff-grid">
            {staff.map((m) => {
              const active = m.is_active !== false;
              return (
                <div key={m.id} className="c-staff-card">
                  <div className="c-staff-avatar">{(m.name || '?').charAt(0).toUpperCase()}</div>
                  <div className="c-staff-body">
                    <span className="c-staff-name">{m.name}</span>
                    <span className={active ? 'c-chip c-chip-completed' : 'c-chip c-chip-cancelled'} style={{ alignSelf: 'flex-start' }}>
                      {active ? 'Active' : 'Inactive'}
                    </span>
                  </div>
                  <div className="c-staff-actions">
                    <button className="c-icon-btn" aria-label="Rename" disabled={busyId === m.id} onClick={() => void rename(m)}>
                      <Icons.Edit size={15} />
                    </button>
                    <button className={`c-icon-btn${active ? ' on' : ''}`} aria-label={active ? 'Disable' : 'Enable'} disabled={busyId === m.id} onClick={() => void toggleActive(m)}>
                      <Icons.Power size={15} />
                    </button>
                  </div>
                </div>
              );
            })}
            </div>
          )}
        </>
      )}
    </div></div>
  );
}
