import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getResources, addResource, updateResource, type Resource } from '@/lib/resources';

export default function Resources() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const [items, setItems] = useState<Resource[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [name, setName] = useState('');
  const [type, setType] = useState('room');
  const [adding, setAdding] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const fetch = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true);
    try {
      setItems(await getResources(shop.id));
    } catch {
      setError('Could not load resources.');
    } finally {
      setLoading(false);
    }
  }, [shop?.id]);

  useEffect(() => { void fetch(); }, [fetch]);

  const handleAdd = async () => {
    if (!name.trim() || !shop?.id) return;
    setAdding(true);
    setError('');
    try {
      const r = await addResource(shop.id, { name: name.trim(), type: type.trim() || 'room' });
      setItems((prev) => [...prev, r]);
      setName('');
    } catch {
      setError('Failed to add resource.');
    } finally {
      setAdding(false);
    }
  };

  const rename = async (r: Resource) => {
    if (!shop?.id) return;
    const val = window.prompt(`Rename ${r.name}`, r.name);
    if (!val?.trim() || val.trim() === r.name) return;
    setBusyId(r.id);
    try {
      const u = await updateResource(shop.id, r.id, { name: val.trim() });
      setItems((prev) => prev.map((x) => (x.id === r.id ? u : x)));
    } catch {
      setError('Could not rename.');
    } finally {
      setBusyId(null);
    }
  };

  const toggleActive = async (r: Resource) => {
    if (!shop?.id) return;
    setBusyId(r.id);
    try {
      const u = await updateResource(shop.id, r.id, { is_active: !r.is_active });
      setItems((prev) => prev.map((x) => (x.id === r.id ? u : x)));
    } catch {
      setError('Could not update.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate('/settings')}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Rooms & Resources</h1>
        <p className="c-page-sub">Rooms, chairs or machines a booking needs. A service can require a resource type by name (e.g. <b>room</b>).</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      <div className="c-staff-add">
        <div className="c-input-row">
          <input type="text" placeholder="Resource name (e.g. Room 1)" value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') void handleAdd(); }} />
        </div>
        <div className="c-input-row" style={{ maxWidth: 140 }}>
          <input type="text" placeholder="type" value={type} onChange={(e) => setType(e.target.value)} />
        </div>
        <button className="c-btn" style={{ height: 54, padding: '0 18px' }} disabled={adding || !name.trim()} onClick={() => void handleAdd()}>
          <Icons.Plus size={16} /> {adding ? 'Adding…' : 'Add'}
        </button>
      </div>

      {loading ? (
        <Spinner label="Loading resources…" />
      ) : items.length === 0 ? (
        <EmptyState title="No resources yet" subtitle="Add rooms or equipment, then require them on a service." />
      ) : (
        <div className="c-dtable-wrap">
          <table className="c-dtable">
            <thead>
              <tr><th>Name</th><th style={{ width: 100 }}>Type</th><th style={{ width: 100 }}>Status</th><th className="ta-r" style={{ width: 110 }}>Actions</th></tr>
            </thead>
            <tbody>
              {items.map((r) => {
                const active = r.is_active !== false;
                return (
                  <tr key={r.id}>
                    <td className="c-dt-namecell"><span className="c-dt-name">{r.name}</span></td>
                    <td>{r.type}</td>
                    <td><span className={active ? 'c-chip c-chip-completed' : 'c-chip c-chip-cancelled'}>{active ? 'Active' : 'Inactive'}</span></td>
                    <td className="c-dt-act">
                      <button className="c-icon-btn" aria-label="Rename" disabled={busyId === r.id} onClick={() => void rename(r)}><Icons.Edit size={15} /></button>
                      <button className={`c-icon-btn${active ? ' on' : ''}`} aria-label={active ? 'Disable' : 'Enable'} disabled={busyId === r.id} onClick={() => void toggleActive(r)}><Icons.Power size={15} /></button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div></div>
  );
}
