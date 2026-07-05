import { useEffect, useState } from 'react';
import { useSubscription } from '@/context/SubscriptionContext';
import { startCheckout } from '@/lib/subscription';

const aed = (fils: number) => `AED ${(fils / 100).toLocaleString('en-AE')}`;

export default function Subscribe() {
  const { sub, refresh } = useSubscription();
  const [busy, setBusy] = useState<'monthly' | 'annual' | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const p = new URLSearchParams(window.location.search).get('pay');
    if (p === 'success') void refresh();
  }, [refresh]);

  const choose = async (plan: 'monthly' | 'annual') => {
    setBusy(plan);
    setError('');
    try {
      const { redirect_url } = await startCheckout(plan);
      window.location.href = redirect_url;
    } catch {
      setError('Could not start payment. Please try again.');
      setBusy(null);
    }
  };

  const monthly = sub?.prices.monthly ?? 14900;
  const annual = sub?.prices.annual ?? 100000;

  return (
    <div className="m-screen c-subscribe"><div className="m-scroll">
      <div className="c-page-head" style={{ paddingTop: 18 }}>
        <h1 className="c-page-title">Subscribe</h1>
        <p className="c-page-sub">Unlock Booking Lens + Ask for your business.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      <div className="svc-grid">
        <div className="c-svc-card">
          <div className="c-svc-body">
            <div className="c-svc-head">
              <span className="c-row-title">Monthly</span>
              <span className="c-svc-price-inline">{aed(monthly)}</span>
            </div>
            <div className="c-row-sub">Billed every 30 days.</div>
          </div>
          <div className="c-svc-actions">
            <button className="c-btn c-btn-block" disabled={busy !== null} onClick={() => void choose('monthly')}>
              {busy === 'monthly' ? 'Starting…' : 'Choose Monthly'}
            </button>
          </div>
        </div>

        <div className="c-svc-card">
          <div className="c-svc-body">
            <div className="c-svc-head">
              <span className="c-row-title">Annual <em>· best value</em></span>
              <span className="c-svc-price-inline">{aed(annual)}</span>
            </div>
            <div className="c-row-sub">One year of access — the cheaper way to pay.</div>
          </div>
          <div className="c-svc-actions">
            <button className="c-btn c-btn-block" disabled={busy !== null} onClick={() => void choose('annual')}>
              {busy === 'annual' ? 'Starting…' : 'Choose Annual'}
            </button>
          </div>
        </div>
      </div>
    </div></div>
  );
}
