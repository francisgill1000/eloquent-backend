import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getServiceCategories, registerShop } from '@/lib/shops';
import { useShop } from '@/context/ShopContext';
import type { ServiceCategory } from '@/types';

type Created = { name: string; email: string; password: string };

export default function MasterShopCreate() {
  const navigate = useNavigate();
  const { shop: me } = useShop();
  const [categories, setCategories] = useState<ServiceCategory[]>([]);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [created, setCreated] = useState<Created | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (me && !me.is_master) navigate('/');
  }, [me, navigate]);

  useEffect(() => {
    getServiceCategories().then(setCategories).catch(() => { /* non-fatal: empty list */ });
  }, []);

  const resetForm = () => { setName(''); setPhone(''); setEmail(''); setPassword(''); setCategoryId(''); };

  const handleSave = async () => {
    if (!name.trim()) { setError('Business name is required.'); return; }
    if (!email.trim()) { setError('Email is required.'); return; }
    if (password.trim().length < 8) { setError('Password must be at least 8 characters.'); return; }
    if (!categoryId) { setError('Please choose a category.'); return; }
    setSaving(true);
    setError('');
    try {
      const res = await registerShop({
        name: name.trim(),
        phone: phone.trim() || undefined,
        email: email.trim(),
        password,
        category_id: Number(categoryId),
        is_verified: true,
      });
      setCreated({
        name: res.shop?.name ?? name.trim(),
        email: email.trim(),
        password,
      });
      resetForm();
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Could not create the business.');
    } finally {
      setSaving(false);
    }
  };

  const copyCreds = async () => {
    if (!created) return;
    try {
      await navigator.clipboard.writeText(`Email: ${created.email}\nPassword: ${created.password}`);
      setCopied(true);
      setTimeout(() => setCopied(false), 1200);
    } catch { /* values stay visible */ }
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
            <span><b>Email</b> {created.email}</span>
            <span><b>Password</b> {created.password}</span>
            <button className="c-icon-btn" aria-label="Copy new credentials" onClick={() => void copyCreds()}>
              {copied ? <Icons.Check size={14} /> : <Icons.Copy size={14} />}
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

          <label className="c-field-label" htmlFor="mb-email">Email</label>
          <div className="c-input-row">
            <input id="mb-email" type="email" placeholder="owner@business.com" value={email}
              onChange={(e) => { setEmail(e.target.value); setError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-password">Password</label>
          <div className="c-input-row">
            <input id="mb-password" type="text" placeholder="At least 8 characters" value={password}
              onChange={(e) => { setPassword(e.target.value); setError(''); }} />
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
