import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { AppBar } from '@/layout/AppBar';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { listCatalogs, deleteCatalog } from '@/lib/catalogs';
import type { Service } from '@/types';

const UNCATEGORISED = 'Uncategorised';

// Group services by parent category name; categorised sections come first
// (in first-seen order), with uncategorised services last.
function groupByParentCategory(items: Service[]): { name: string; items: Service[] }[] {
  const groups = new Map<string, Service[]>();
  for (const item of items) {
    const name = item.parent_category?.name ?? UNCATEGORISED;
    if (!groups.has(name)) groups.set(name, []);
    groups.get(name)!.push(item);
  }
  return [...groups.entries()]
    .map(([name, groupItems]) => ({ name, items: groupItems }))
    .sort((a, b) => (a.name === UNCATEGORISED ? 1 : b.name === UNCATEGORISED ? -1 : 0));
}

export default function Services() {
  const navigate = useNavigate();
  const [catalogs, setCatalogs] = useState<Service[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const fetchCatalogs = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setCatalogs(await listCatalogs());
    } catch {
      setError('Could not load services.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void fetchCatalogs(); }, [fetchCatalogs]);

  const handleDelete = async (id: number) => {
    if (!window.confirm('Delete this service? This cannot be undone.')) return;
    setDeletingId(id);
    try {
      await deleteCatalog(id);
      setCatalogs((prev) => prev.filter((c) => c.id !== id));
    } catch {
      setError('Failed to delete service.');
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <div className="m-screen c-services">
      <AppBar
        title="Services"
        actions={
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="c-btn-ghost" style={{ padding: '8px 10px', fontSize: 13 }} onClick={() => navigate('/categories')}>Categories</button>
            <button className="c-btn" style={{ padding: '8px 12px', fontSize: 13 }} onClick={() => navigate('/services/new')}>+ Add</button>
          </div>
        }
      />
      <div className="m-scroll">
        {error && <div className="c-error-box">{error}</div>}

        {loading ? (
          <Spinner label="Loading services…" />
        ) : catalogs.length > 0 ? (
          groupByParentCategory(catalogs).map((group) => (
            <div key={group.name} className="svc-group">
              <div className="m-section-title" style={{ padding: '12px 16px 4px' }}><h3>{group.name}</h3></div>
              <div className="svc-grid">
              {group.items.map((c) => (
            <div key={c.id} className="c-svc-card">
              <div className="c-svc-body">
                <div className="c-svc-head">
                  <span className="c-row-title">{c.title || c.name}</span>
                  <span className="c-svc-price-inline">AED {Number(c.price ?? 0).toFixed(2)}</span>
                </div>
                {c.description && <div className="c-row-sub">{c.description}</div>}
              </div>
              <div className="c-svc-actions">
                <button className="c-btn-ghost" onClick={() => navigate(`/services/${c.id}/edit`)}>
                  <Icons.Edit size={14} /> Edit
                </button>
                <button className="c-btn-ghost" style={{ color: 'var(--danger)' }} disabled={deletingId === c.id} onClick={() => void handleDelete(c.id)}>
                  <Icons.Trash size={14} /> {deletingId === c.id ? 'Deleting…' : 'Delete'}
                </button>
              </div>
            </div>
              ))}
              </div>
            </div>
          ))
        ) : (
          <EmptyState
            title="No services yet"
            subtitle="Add the services your business offers."
            action={<button className="c-btn" onClick={() => navigate('/services/new')}>Add a Service</button>}
          />
        )}
      </div>
    </div>
  );
}
