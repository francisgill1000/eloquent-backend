import { useState } from 'react';
import { useNavigate, useLocation, Link } from 'react-router-dom';
import { resetPin } from '@/lib/shops';
import { storage } from '@/lib/storage';
import { Icons } from '@/components/Icons';

type ResetResult = { shopCode: string; pin: string };

export default function ForgotPin() {
  const navigate = useNavigate();
  const location = useLocation();
  const initialCode = (location.state as { shopCode?: string } | null)?.shopCode ?? '';
  const [shopCode, setShopCode] = useState(initialCode);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState<ResetResult | null>(null);

  const handleReset = async () => {
    if (submitting) return;
    if (!shopCode.trim()) { setError('Please enter your Business ID.'); return; }
    setSubmitting(true);
    setError('');
    try {
      const data = (await resetPin(shopCode.trim())) as { pin?: string; shop_code?: string };
      if (data?.pin) {
        setResult({ shopCode: data.shop_code ?? shopCode.trim(), pin: data.pin });
      } else {
        setError('Unable to reset PIN. Please try again.');
      }
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Failed to reset PIN. Please check your Business ID.');
    } finally {
      setSubmitting(false);
    }
  };

  const goToLogin = () => {
    if (result) {
      storage.set('post_reset_login_prefill', JSON.stringify({ shopCode: result.shopCode, pin: result.pin }));
    }
    navigate('/login');
  };

  return (
    <div className="m-screen c-auth-screen"><div className="m-scroll c-auth-scroll">
      <div className="c-auth">
        <div className="c-auth-brand">
          <div className="c-auth-orb"><img src="/favicon.svg" alt="" /></div>
          <div className="c-auth-wordmark">AI Business Manager</div>
        </div>

        <div className="c-auth-card">
        <button className="c-back" onClick={() => navigate(-1)}>
          <Icons.ChevronLeft size={16} /> Back
        </button>

        {!result ? (
          <>
            <h1 className="c-auth-title">Forgot PIN?</h1>
            <p className="c-auth-sub">Enter your Business ID and we'll generate a new PIN for your shop.</p>

            {error && <div className="c-error-box">{error}</div>}

            <label className="c-field-label" htmlFor="shop-code">Business ID</label>
            <div className="c-input-row">
              <input id="shop-code" type="text" placeholder="Enter business code" autoCapitalize="none"
                value={shopCode} onChange={(e) => { setShopCode(e.target.value); setError(''); }} />
            </div>

            <button className="c-btn c-btn-block" disabled={submitting} onClick={() => void handleReset()}>
              {submitting ? 'Resetting…' : 'Reset PIN'}
            </button>
          </>
        ) : (
          <>
            <h1 className="c-auth-title">New PIN Generated</h1>
            <p className="c-auth-sub">Save this PIN somewhere safe. You'll need it to log in.</p>

            <div className="c-card" style={{ marginBottom: 12 }}>
              <div className="c-field-label" style={{ marginBottom: 4 }}>Business ID</div>
              <div style={{ fontWeight: 700, fontSize: 18 }}>{result.shopCode}</div>
            </div>

            <div className="c-card" style={{ marginBottom: 20, borderColor: 'var(--border-mint)' }}>
              <div className="c-field-label" style={{ marginBottom: 4 }}>New PIN</div>
              <div style={{ fontWeight: 800, fontSize: 28, letterSpacing: 8, color: 'var(--mint-400)' }}>{result.pin}</div>
            </div>

            <button className="c-btn c-btn-block" onClick={goToLogin}>Continue to Login</button>
          </>
        )}
        </div>

        <p className="c-muted-center">
          Remembered it? <Link className="c-link" to="/login">Back to Login</Link>
        </p>
      </div>
    </div></div>
  );
}
