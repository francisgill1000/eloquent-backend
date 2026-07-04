import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getServiceCategories, registerShop } from '@/lib/shops';
import type { ServiceCategory } from '@/types';

type Created = { name: string; code: string; pin: string };

export default function MasterShopCreate() {
  const navigate = useNavigate();
  const [categories, setCategories] = useState<ServiceCategory[]>([]);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [created, setCreated] = useState<Created | null>(null);

  useEffect(() => {
    getServiceCategories().then(setCategories).catch(() => { /* non-fatal: empty list */ });
  }, []);

  const resetForm = () => { setName(''); setPhone(''); setCategoryId(''); };

  const handleSave = async () => {
    if (!name.trim()) { setError('Business name is required.'); return; }
    if (!phone.trim()) { setError('Phone number is required.'); return; }
    if (!categoryId) { setError('Please choose a category.'); return; }
    setSaving(true);
    setError('');
    try {
      const res = await registerShop({
        name: name.trim(),
        phone: phone.trim(),
        category_id: Number(categoryId),
        is_verified: true,
      });
      setCreated({
        name: res.shop?.name ?? name.trim(),
        code: String(res.shop?.shop_code ?? ''),
        pin: String(res.shop?.pin ?? ''),
      });
      resetForm();
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Could not create the business.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="m-screen c-svc-edit"><div className="m-scroll">
      <div className="svc-edit-wrap">
      <button className="c-back" onClick={() => navigate('/master')}><Icons.ChevronLeft size={16} /> Back</button>
      <h1 className="c-auth-title" style={{ textAlign: 'left', margin: '0 16px 16px' }}>Add Business</h1>

      {error && <div className="c-error-box">{error}</div>}

      {created ? (
        <div className="svc-form">
          <div className="c-master-top" style={{ marginBottom: 10 }}>
            <span className="c-master-name">{created.name} <em>· created ✓</em></span>
          </div>
          <p className="c-msd-sub" style={{ marginTop: 0 }}>Send these login details to the owner.</p>
          <div className="c-master-creds">
            <span><b>ID</b> {created.code}</span>
            <span><b>PIN</b> {created.pin}</span>
            <button className="c-icon-btn" aria-label="Copy new credentials"
              onClick={() => void navigator.clipboard.writeText(`Business ID: ${created.code}\nPIN: ${created.pin}`).catch(() => undefined)}>
              <Icons.Copy size={14} />
            </button>
          </div>
          <button className="c-btn c-btn-block" style={{ marginTop: 16 }} onClick={() => navigate('/master')}>
            Back to Businesses
          </button>
          <button className="c-btn-ghost c-btn-block" style={{ marginTop: 8 }} onClick={() => setCreated(null)}>
            Add another
          </button>
        </div>
      ) : (
        <div className="svc-form">
          <label className="c-field-label" htmlFor="mb-name">Business Name</label>
          <div className="c-input-row">
            <input id="mb-name" type="text" placeholder="e.g. Glow Salon" value={name}
              onChange={(e) => { setName(e.target.value); setError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-phone">Phone Number</label>
          <div className="c-input-row">
            <input id="mb-phone" type="tel" placeholder="+9715xxxxxxxx" value={phone}
              onChange={(e) => { setPhone(e.target.value); setError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-category">Service Category</label>
          <div className="c-input-row">
            <select id="mb-category" value={categoryId}
              onChange={(e) => { setCategoryId(e.target.value); setError(''); }}
              style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', font: 'inherit' }}>
              <option value="" disabled>Choose category…</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>

          <button className="c-btn c-btn-block" disabled={saving} onClick={() => void handleSave()}>
            {saving ? 'Creating…' : 'Create Business'}
          </button>
        </div>
      )}
      </div>
    </div></div>
  );
}
