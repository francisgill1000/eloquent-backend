import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { AppBar } from '@/layout/AppBar';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { listCatalogs, deleteCatalog } from '@/lib/catalogs';
import { listParentCategories, deleteParentCategory } from '@/lib/parentCategories';
import type { Service, ParentCategory } from '@/types';

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
  const [tab, setTab] = useState<'services' | 'categories'>('services');

  const [catalogs, setCatalogs] = useState<Service[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const [cats, setCats] = useState<ParentCategory[]>([]);
  const [catsLoading, setCatsLoading] = useState(true);
  const [deletingCatId, setDeletingCatId] = useState<number | null>(null);

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

  const fetchCats = useCallback(async () => {
    setCatsLoading(true);
    try {
      setCats(await listParentCategories());
    } catch {
      /* non-fatal: empty list */
    } finally {
      setCatsLoading(false);
    }
  }, []);

  useEffect(() => { void fetchCatalogs(); void fetchCats(); }, [fetchCatalogs, fetchCats]);

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

  const handleDeleteCat = async (id: number) => {
    if (!window.confirm('Delete this category? Services in it become uncategorised.')) return;
    setDeletingCatId(id);
    try {
      await deleteParentCategory(id);
      setCats((prev) => prev.filter((c) => c.id !== id));
    } catch {
      setError('Failed to delete category.');
    } finally {
      setDeletingCatId(null);
    }
  };

  const addTo = tab === 'services' ? '/services/new' : '/categories/new';

  return (
    <div className="m-screen c-services">
      <AppBar
        title="Services"
        actions={
          <button className="c-btn" style={{ padding: '8px 12px', fontSize: 13 }} onClick={() => navigate(addTo)}>
            + Add
          </button>
        }
      />
      <div className="m-scroll">
        {error && <div className="c-error-box">{error}</div>}

        <div className="ac-tabs" role="tablist" aria-label="Services and categories">
          <button type="button" role="tab" aria-selected={tab === 'services'} className={`ac-tab${tab === 'services' ? ' on' : ''}`} onClick={() => setTab('services')}>
            Services <span className="ac-tab-count">{catalogs.length}</span>
          </button>
          <button type="button" role="tab" aria-selected={tab === 'categories'} className={`ac-tab${tab === 'categories' ? ' on' : ''}`} onClick={() => setTab('categories')}>
            Categories <span className="ac-tab-count">{cats.length}</span>
          </button>
        </div>

        {tab === 'services' ? (
          loading ? (
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
          )
        ) : catsLoading ? (
          <Spinner label="Loading categories…" />
        ) : cats.length > 0 ? (
          <div className="svc-grid">
            {cats.map((c) => (
              <div key={c.id} className="c-svc-card">
                <div className="c-svc-media">
                  {c.image ? (
                    <img src={c.image} alt={c.name} />
                  ) : (
                    <div className="c-svc-media-empty"><Icons.Image size={28} /><span>No image</span></div>
                  )}
                </div>
                <div className="c-svc-body">
                  <div className="c-row-title">{c.name}</div>
                </div>
                <div className="c-svc-actions">
                  <button className="c-btn-ghost" onClick={() => navigate(`/categories/${c.id}/edit`)}>
                    <Icons.Edit size={14} /> Edit
                  </button>
                  <button className="c-btn-ghost" style={{ color: 'var(--danger)' }} disabled={deletingCatId === c.id} onClick={() => void handleDeleteCat(c.id)}>
                    <Icons.Trash size={14} /> {deletingCatId === c.id ? 'Deleting…' : 'Delete'}
                  </button>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <EmptyState
            title="No categories yet"
            subtitle="Create a category to group your services into sections."
            action={<button className="c-btn" onClick={() => navigate('/categories/new')}>Add a Category</button>}
          />
        )}
      </div>
    </div>
  );
}
