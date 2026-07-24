import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { shopLogin } from '@/lib/shops';
import { useShop } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import { shopHasModule } from '@/lib/modules';
import type { Shop } from '@/types';

/**
 * Where to send a shop straight after login. A Business Hunt shop lands on its
 * Overview dashboard; everyone else keeps the Ask home. Guarded so a
 * bookings-only shop (no /hunt-insights) and the master account never go there.
 */
function landingPath(shop: Shop, permissions: string[] | null): string {
  const canViewLeads = permissions === null
    || permissions.includes('*')
    || permissions.includes('leads.view');
  if (!shop.is_master && shopHasModule(shop, 'leads') && canViewLeads) {
    return '/hunt-insights';
  }
  return '/';
}

export default function Login() {
  const navigate = useNavigate();
  const { loginShop, setAccess } = useShop();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (storage.get('remember_shop_login') === 'true') {
      setRememberMe(true);
      setEmail(storage.get('remember_shop_email') ?? '');
    }
  }, []);

  const handleLogin = async () => {
    if (submitting) return;
    if (!email.trim()) { setError('Please enter your email.'); return; }
    if (!password.trim()) { setError('Please enter your password.'); return; }
    setSubmitting(true);
    setError('');
    try {
      const { token, shop, user, permissions } = await shopLogin(email.trim(), password);
      if (token && shop) {
        if (rememberMe) {
          storage.set('remember_shop_login', 'true');
          storage.set('remember_shop_email', email.trim());
        } else {
          storage.remove('remember_shop_login');
          storage.remove('remember_shop_email');
        }
        loginShop(shop, token);
        setAccess(user, permissions);
        navigate(landingPath(shop, permissions));
      } else {
        setError('Invalid response from server.');
      }
    } catch (e: unknown) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message || 'Login failed. Check your credentials.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="m-screen c-auth-screen"><div className="m-scroll c-auth-scroll">
      <div className="c-auth">
        <div className="c-auth-brand">
          <div className="c-auth-orb"><img src="/favicon.svg" alt="" /></div>
          <div className="c-auth-wordmark">Business Lens</div>
        </div>

        <div className="c-auth-card">
        <h1 className="c-auth-title">Welcome back</h1>
        <p className="c-auth-sub">Enter your email and password to access your dashboard.</p>

        {error && <div className="c-error-box">{error}</div>}

        <label className="c-field-label" htmlFor="email">Email</label>
        <div className="c-input-row">
          <input
            id="email"
            type="email"
            placeholder="you@business.com"
            autoCapitalize="none"
            value={email}
            onChange={(e) => { setEmail(e.target.value); setError(''); }}
          />
        </div>

        <label className="c-field-label" htmlFor="password">Password</label>
        <div className="c-input-row">
          <input
            id="password"
            type={showPassword ? 'text' : 'password'}
            placeholder="Enter your password"
            value={password}
            onChange={(e) => { setPassword(e.target.value); setError(''); }}
            onKeyDown={(e) => { if (e.key === 'Enter') void handleLogin(); }}
          />
          <button
            type="button"
            onClick={() => setShowPassword((v) => !v)}
            style={{ background: 'none', border: 'none', color: 'var(--text-3)', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', cursor: 'pointer' }}
          >
            {showPassword ? 'Hide' : 'Show'}
          </button>
        </div>

        <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16, color: 'var(--text-2)', fontSize: 14 }}>
          <input type="checkbox" checked={rememberMe} onChange={(e) => setRememberMe(e.target.checked)} />
          Remember me
        </label>

        <button className="c-btn c-btn-block" disabled={submitting} onClick={() => void handleLogin()}>
          {submitting ? 'Logging in…' : 'Log In'}
        </button>
        </div>
      </div>
    </div></div>
  );
}
