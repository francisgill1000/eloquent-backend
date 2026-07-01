import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getCatalog, createCatalog, updateCatalog } from '@/lib/catalogs';
import { listParentCategories, createParentCategory } from '@/lib/parentCategories';
import type { ParentCategory } from '@/types';

type Form = { title: string; description: string; price: string };

export default function ServiceEdit() {
  const { id } = useParams<{ id: string }>();
  const isNew = !id;
  const navigate = useNavigate();
  const [form, setForm] = useState<Form>({ title: '', description: '', price: '' });
  const [loading, setLoading] = useState(!isNew);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const [cats, setCats] = useState<ParentCategory[]>([]);
  const [parentCategoryId, setParentCategoryId] = useState<number | null>(null);
  const [addingCat, setAddingCat] = useState(false);
  const [newCatName, setNewCatName] = useState('');
  const [creatingCat, setCreatingCat] = useState(false);

  useEffect(() => {
    listParentCategories().then(setCats).catch(() => { /* non-fatal: empty list */ });
  }, []);

  useEffect(() => {
    if (isNew) return;
    getCatalog(Number(id))
      .then((d) => {
        setForm({ title: d.title || d.name || '', description: d.description || '', price: String(d.price ?? '') });
        if (d.parent_category_id != null) setParentCategoryId(Number(d.parent_category_id));
      })
      .catch(() => setError('Failed to load service.'))
      .finally(() => setLoading(false));
  }, [id, isNew]);

  const handleCreateCat = async () => {
    const name = newCatName.trim();
    if (!name || creatingCat) return;
    setCreatingCat(true);
    setError('');
    try {
      const cat = await createParentCategory(name);
      setCats((prev) => [...prev, cat]);
      setParentCategoryId(cat.id);
      setNewCatName('');
      setAddingCat(false);
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Failed to create category.');
    } finally {
      setCreatingCat(false);
    }
  };

  const change = (key: keyof Form, value: string) => setForm((f) => ({ ...f, [key]: value }));

  const handleSave = async () => {
    if (!form.title.trim()) { setError('Please enter a service title.'); return; }
    if (!form.price || parseFloat(form.price) <= 0) { setError('Please enter a valid price.'); return; }
    setSaving(true);
    setError('');
    try {
      const payload: Record<string, unknown> = {
        title: form.title.trim(),
        description: form.description.trim(),
        price: form.price,
        parent_category_id: parentCategoryId,
      };
      if (isNew) await createCatalog(payload);
      else await updateCatalog(Number(id), payload);
      navigate('/services');
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Failed to save service.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading service…" /></div>;

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate('/services')}><Icons.ChevronLeft size={16} /> Back</button>
      <h1 className="c-auth-title" style={{ textAlign: 'left', margin: '0 16px 16px' }}>{isNew ? 'Add Service' : 'Edit Service'}</h1>

      {error && <div className="c-error-box">{error}</div>}

      <div style={{ padding: '0 16px' }}>
        <label className="c-field-label" htmlFor="parentCategory">Parent category (optional)</label>
        {addingCat ? (
          <div className="c-input-row" style={{ gap: 8 }}>
            <input
              id="parentCategory"
              type="text"
              placeholder="New category name"
              value={newCatName}
              autoFocus
              onChange={(e) => { setNewCatName(e.target.value); setError(''); }}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); void handleCreateCat(); } }}
            />
            <button type="button" className="c-btn" style={{ padding: '8px 12px', fontSize: 13, whiteSpace: 'nowrap' }}
              disabled={creatingCat || !newCatName.trim()} onClick={() => void handleCreateCat()}>
              {creatingCat ? 'Adding…' : 'Add'}
            </button>
            <button type="button" className="c-btn-ghost" style={{ padding: '8px 10px' }}
              onClick={() => { setAddingCat(false); setNewCatName(''); }}>
              Cancel
            </button>
          </div>
        ) : (
          <div className="c-input-row">
            <select
              id="parentCategory"
              value={parentCategoryId == null ? '' : String(parentCategoryId)}
              onChange={(e) => {
                if (e.target.value === '__new__') { setAddingCat(true); return; }
                setParentCategoryId(e.target.value ? Number(e.target.value) : null);
              }}
              style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', font: 'inherit' }}
            >
              <option value="">None</option>
              {cats.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
              <option value="__new__">+ New category…</option>
            </select>
          </div>
        )}

        <label className="c-field-label" htmlFor="title">Title</label>
        <div className="c-input-row">
          <input id="title" type="text" placeholder="Service title" value={form.title}
            onChange={(e) => { change('title', e.target.value); setError(''); }} />
        </div>

        <label className="c-field-label" htmlFor="description">Description (optional)</label>
        <div className="c-input-row" style={{ alignItems: 'flex-start' }}>
          <textarea id="description" rows={3} placeholder="Describe this service" value={form.description}
            onChange={(e) => { change('description', e.target.value); setError(''); }}
            style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', resize: 'vertical', font: 'inherit' }} />
        </div>

        <label className="c-field-label" htmlFor="price">Price (AED)</label>
        <div className="c-input-row">
          <input id="price" type="number" inputMode="decimal" min="0" step="0.01" placeholder="0.00" value={form.price}
            onChange={(e) => { change('price', e.target.value); setError(''); }} />
        </div>

        <button className="c-btn c-btn-block" disabled={saving} onClick={() => void handleSave()}>
          {saving ? 'Saving…' : isNew ? 'Create Service' : 'Save Changes'}
        </button>
      </div>
    </div></div>
  );
}
