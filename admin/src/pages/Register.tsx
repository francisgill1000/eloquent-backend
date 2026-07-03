import { useEffect, useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { getServiceCategories, registerShop } from '@/lib/shops';
import { useShop } from '@/context/ShopContext';
import { Icons } from '@/components/Icons';
import type { ServiceCategory, Shop } from '@/types';

// Select value for "Other"; submitted to the API as category_id 0 with a
// free-text custom_category the owner types in.
const OTHER = 'other';

export default function Register() {
  const navigate = useNavigate();
  const { loginShop } = useShop();
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [customCategory, setCustomCategory] = useState('');
  const [categories, setCategories] = useState<ServiceCategory[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [created, setCreated] = useState<{ shop: Shop; token?: string } | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    let alive = true;
    getServiceCategories()
      .then((list) => { if (alive) setCategories(list); })
      .catch(() => undefined);
    return () => { alive = false; };
  }, []);

  const handleSubmit = async () => {
    if (!name.trim()) { setError('Business name is required.'); return; }
    if (!phone.trim()) { setError('Phone number is required.'); return; }
    if (!categoryId) { setError('Please choose your service category.'); return; }
    const isOther = categoryId === OTHER;
    if (isOther && !customCategory.trim()) { setError('Please enter your business category.'); return; }
    setSubmitting(true);
    setError('');
    try {
      const res = await registerShop({
        name: name.trim(),
        phone: phone.trim(),
        category_id: isOther ? 0 : Number(categoryId),
        ...(isOther ? { custom_category: customCategory.trim() } : {}),
        is_verified: true,
      });
      if (res.shop) {
        setCreated({ shop: res.shop, token: res.token });
      } else {
        navigate('/login');
      }
    } catch (e: unknown) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message || 'Registration failed. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  if (created) {
    const { shop, token } = created;
    const creds = `Business ID: ${shop.shop_code ?? ''}\nPIN: ${shop.pin ?? ''}`;

    const copyCreds = async () => {
      try {
        await navigator.clipboard.writeText(creds);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
      } catch { /* clipboard unavailable — values stay visible on screen */ }
    };

    const continueIn = () => {
      if (token) {
        loginShop(shop, token); // already authenticated — go straight in
        navigate('/');
      } else {
        navigate('/login');
      }
    };

    return (
      <div className="m-screen c-auth-screen"><div className="m-scroll c-auth-scroll">
        <div className="c-auth">
          <div className="c-auth-brand">
            <div className="c-auth-orb"><img src="/favicon.svg" alt="" /></div>
            <div className="c-auth-wordmark">AI Business Manager</div>
          </div>

          <div className="c-auth-card">
          <h1 className="c-auth-title">Business Registered 🎉</h1>
          <p className="c-auth-sub">
            Save these login details — you need them every time you log in.
          </p>

          <div className="c-cred-card">
            <div className="c-cred-row">
              <span className="c-cred-label">Business ID</span>
              <span className="c-cred-value">{String(shop.shop_code ?? '—')}</span>
            </div>
            <div className="c-cred-row">
              <span className="c-cred-label">PIN</span>
              <span className="c-cred-value">{String(shop.pin ?? '—')}</span>
            </div>
            <button className="c-btn-ghost" style={{ width: '100%' }} onClick={() => void copyCreds()}>
              <Icons.Copy size={15} /> {copied ? 'Copied ✓' : 'Copy details'}
            </button>
          </div>

          <button className="c-btn c-btn-block" onClick={continueIn}>
            {token ? 'Continue to Dashboard' : 'Continue to Login'}
          </button>
          </div>
        </div>
      </div></div>
    );
  }

  return (
    <div className="m-screen c-auth-screen"><div className="m-scroll c-auth-scroll">
      <div className="c-auth">
        <div className="c-auth-brand">
          <div className="c-auth-orb"><img src="/favicon.svg" alt="" /></div>
          <div className="c-auth-wordmark">AI Business Manager</div>
        </div>

        <div className="c-auth-card">
        <h1 className="c-auth-title">Register your business</h1>
        <p className="c-auth-sub">Just a few details — you can add the rest later in your profile.</p>

        {error && <div className="c-error-box">{error}</div>}

        <label className="c-field-label" htmlFor="name">Business Name</label>
        <div className="c-input-row">
          <input id="name" type="text" placeholder="e.g. Glow Salon" value={name}
            onChange={(e) => { setName(e.target.value); setError(''); }} />
        </div>

        <label className="c-field-label" htmlFor="phone">Phone Number</label>
        <div className="c-input-row">
          <input id="phone" type="tel" placeholder="+9715xxxxxxxx" value={phone}
            onChange={(e) => { setPhone(e.target.value); setError(''); }}
            onKeyDown={(e) => { if (e.key === 'Enter') void handleSubmit(); }} />
        </div>

        <label className="c-field-label" htmlFor="category">Service Category</label>
        <div className="c-input-row">
          <select id="category" value={categoryId}
            onChange={(e) => { setCategoryId(e.target.value); setError(''); }}>
            <option value="" disabled>Choose your category…</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
            <option value={OTHER}>Other…</option>
          </select>
        </div>
        {categoryId === OTHER && (
          <div className="c-input-row">
            <input id="custom-category" type="text" placeholder="Type your business category"
              value={customCategory} maxLength={255}
              onChange={(e) => { setCustomCategory(e.target.value); setError(''); }}
              onKeyDown={(e) => { if (e.key === 'Enter') void handleSubmit(); }} />
          </div>
        )}
        <p className="c-page-sub" style={{ margin: '-6px 4px 14px', fontSize: 12 }}>
          This cannot be changed later — choose carefully.
        </p>

        <button className="c-btn c-btn-block" disabled={submitting} onClick={() => void handleSubmit()}>
          {submitting ? 'Registering…' : 'Register Business'}
        </button>
        </div>

        <p className="c-muted-center">
          Already have a business? <Link className="c-link" to="/login">Log In</Link>
        </p>
      </div>
    </div></div>
  );
}
