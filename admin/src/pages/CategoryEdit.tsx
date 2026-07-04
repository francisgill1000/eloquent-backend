import { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { listParentCategories, createParentCategory, updateParentCategory } from '@/lib/parentCategories';
import { fileToCompressedDataUrl } from '@/lib/image';

export default function CategoryEdit() {
  const { id } = useParams<{ id: string }>();
  const isNew = !id;
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [image, setImage] = useState<string | null>(null);
  const [loading, setLoading] = useState(!isNew);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const imageInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (isNew) return;
    listParentCategories()
      .then((cats) => {
        const cat = cats.find((c) => c.id === Number(id));
        if (!cat) { setError('Category not found.'); return; }
        setName(cat.name);
        if (cat.image) setImage(cat.image);
      })
      .catch(() => setError('Failed to load category.'))
      .finally(() => setLoading(false));
  }, [id, isNew]);

  const handleSave = async () => {
    if (!name.trim()) { setError('Please enter a category name.'); return; }
    setSaving(true);
    setError('');
    try {
      const imagePayload = image && image.startsWith('data:') ? image : undefined;
      if (isNew) {
        await createParentCategory(name.trim(), imagePayload);
      } else {
        const payload: { name: string; image?: string | null } = { name: name.trim() };
        if (image == null) payload.image = null;
        else if (imagePayload) payload.image = imagePayload;
        await updateParentCategory(Number(id), payload);
      }
      navigate('/services', { state: { tab: 'categories' } });
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Failed to save category.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading category…" /></div>;

  return (
    <div className="m-screen c-svc-edit"><div className="m-scroll">
      <div className="svc-edit-wrap">
      <button className="c-back" onClick={() => navigate('/services', { state: { tab: 'categories' } })}><Icons.ChevronLeft size={16} /> Back</button>
      <h1 className="c-auth-title" style={{ textAlign: 'left', margin: '0 16px 16px' }}>{isNew ? 'Add Category' : 'Edit Category'}</h1>

      {error && <div className="c-error-box">{error}</div>}

      <div className="svc-form">
        <label className="c-field-label" htmlFor="image">Image</label>
        <button type="button" className="c-img-pick" onClick={() => imageInput.current?.click()}>
          {image ? (
            <img src={image} alt="Category" />
          ) : (
            <span className="c-img-pick-empty">
              <span className="ic"><Icons.Image size={26} /></span>
              <span className="t">Upload category image</span>
              <span className="h">PNG or JPG</span>
            </span>
          )}
        </button>
        <input id="image" ref={imageInput} type="file" accept="image/*" hidden
          onChange={async (e) => { const f = e.target.files?.[0]; if (f) setImage(await fileToCompressedDataUrl(f, { maxDim: 1280 })); }} />
        {image && (
          <button type="button" className="c-btn-ghost" style={{ margin: '6px 0 0' }} onClick={() => setImage(null)}>
            <Icons.Trash size={14} /> Remove image
          </button>
        )}

        <label className="c-field-label" htmlFor="name">Name</label>
        <div className="c-input-row">
          <input id="name" type="text" placeholder="Category name" value={name}
            onChange={(e) => { setName(e.target.value); setError(''); }} />
        </div>

        <button className="c-btn c-btn-block" disabled={saving} onClick={() => void handleSave()}>
          {saving ? 'Saving…' : isNew ? 'Create Category' : 'Save Changes'}
        </button>
      </div>
      </div>
    </div></div>
  );
}
