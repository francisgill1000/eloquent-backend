import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { listParentCategories, deleteParentCategory } from '@/lib/parentCategories';
import type { ParentCategory } from '@/types';

export default function Categories() {
  const navigate = useNavigate();
  const [cats, setCats] = useState<ParentCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const fetchCats = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setCats(await listParentCategories());
    } catch {
      setError('Could not load categories.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void fetchCats(); }, [fetchCats]);

  const handleDelete = async (id: number) => {
    if (!window.confirm('Delete this category? Services in it become uncategorised.')) return;
    setDeletingId(id);
    try {
      await deleteParentCategory(id);
      setCats((prev) => prev.filter((c) => c.id !== id));
    } catch {
      setError('Failed to delete category.');
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <div className="m-screen">
      <div className="m-appbar">
        <button className="c-back" onClick={() => navigate('/services')}><Icons.ChevronLeft size={16} /> Back</button>
        <button className="c-btn" style={{ padding: '8px 12px', fontSize: 13 }} onClick={() => navigate('/categories/new')}>+ Add</button>
      </div>
      <div className="m-scroll">
        <h1 className="c-auth-title" style={{ textAlign: 'left', margin: '0 16px 4px' }}>Categories</h1>
        <p style={{ margin: '0 16px 16px', color: 'var(--text-3)', fontSize: 13 }}>
          Group your services into sections (e.g. Massage, Body Massage). The image is set here, not per service.
        </p>

        {error && <div className="c-error-box">{error}</div>}

        {loading ? (
          <Spinner label="Loading categories…" />
        ) : cats.length > 0 ? (
          cats.map((c) => (
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
                <button className="c-btn-ghost" style={{ color: 'var(--danger)' }} disabled={deletingId === c.id} onClick={() => void handleDelete(c.id)}>
                  <Icons.Trash size={14} /> {deletingId === c.id ? 'Deleting…' : 'Delete'}
                </button>
              </div>
            </div>
          ))
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
