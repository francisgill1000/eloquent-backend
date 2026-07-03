import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';
import { confirmShopCategory, getServiceCategories } from '@/lib/shops';
import type { ServiceCategory } from '@/types';

/**
 * One-time category selection for shops registered before the category
 * dropdown existed. Once saved, the category is locked for good.
 */
export default function CategorySetup() {
  const navigate = useNavigate();
  const { shop, token, loginShop } = useShop();
  const [categories, setCategories] = useState<ServiceCategory[]>([]);
  const [categoryId, setCategoryId] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let alive = true;
    getServiceCategories()
      .then((list) => { if (alive) setCategories(list); })
      .catch(() => undefined);
    return () => { alive = false; };
  }, []);

  const handleSave = async () => {
    if (!categoryId) { setError('Please choose your service category.'); return; }
    setSaving(true);
    setError('');
    try {
      const updated = await confirmShopCategory(Number(categoryId));
      if (token) loginShop(updated, token); // refresh stored shop (now locked)
      navigate('/');
    } catch (e) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg || 'Could not save your category.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-auth">
        <h1 className="c-auth-title">One last thing</h1>
        <p className="c-auth-sub">
          What kind of business is {shop?.name || 'your business'}? This helps Business Lens serve your
          customers correctly.
        </p>

        {error && <div className="c-error-box">{error}</div>}

        <label className="c-field-label" htmlFor="category">Service Category</label>
        <div className="c-input-row">
          <select id="category" value={categoryId}
            onChange={(e) => { setCategoryId(e.target.value); setError(''); }}>
            <option value="" disabled>Choose your category…</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
        </div>
        <p className="c-page-sub" style={{ margin: '-6px 4px 14px', fontSize: 12 }}>
          This cannot be changed later — choose carefully.
        </p>

        <button className="c-btn c-btn-block" disabled={saving} onClick={() => void handleSave()}>
          {saving ? 'Saving…' : 'Save Category'}
        </button>
      </div>
    </div></div>
  );
}
