import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getMasterPricing, updateMasterPricing } from '@/lib/masterPricing';

/**
 * Master-only page for the subscription plan prices every business pays.
 * Split out of the All Businesses list so that screen stays focused on shops.
 */
export default function MasterPricing() {
  const navigate = useNavigate();
  const [priceMonthly, setPriceMonthly] = useState('');
  const [priceAnnual, setPriceAnnual] = useState('');
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState('');

  useEffect(() => {
    getMasterPricing()
      .then((p) => { setPriceMonthly(String(p.monthly / 100)); setPriceAnnual(String(p.annual / 100)); })
      .catch(() => undefined);
  }, []);

  const save = async () => {
    setBusy(true); setMsg('');
    try {
      await updateMasterPricing({
        monthly_fils: Math.round(parseFloat(priceMonthly) * 100),
        annual_fils: Math.round(parseFloat(priceAnnual) * 100),
      });
      setMsg('Saved ✓');
    } catch {
      setMsg('Could not save prices.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="m-screen c-master"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12, paddingTop: 18 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">Subscription pricing</h1>
          <p className="c-page-sub">The plan prices every business pays.</p>
        </div>
        <button className="c-icon-btn" aria-label="Back to businesses" onClick={() => navigate('/master')}>
          <Icons.ChevronLeft size={18} />
        </button>
      </div>

      <div className="c-master-card">
        <div className="c-msc-top" style={{ marginBottom: 10 }}>
          <span className="c-master-name">Subscription pricing</span>
          {msg && <em style={{ fontSize: 12, color: 'var(--text-3)' }}>{msg}</em>}
        </div>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <div style={{ flex: 1, minWidth: 120 }}>
            <label className="c-field-label" htmlFor="price-monthly">Monthly (AED)</label>
            <div className="c-input-row">
              <input id="price-monthly" type="number" min="2" step="1" value={priceMonthly}
                onChange={(e) => { setPriceMonthly(e.target.value); setMsg(''); }} />
            </div>
          </div>
          <div style={{ flex: 1, minWidth: 120 }}>
            <label className="c-field-label" htmlFor="price-annual">Annual (AED)</label>
            <div className="c-input-row">
              <input id="price-annual" type="number" min="2" step="1" value={priceAnnual}
                onChange={(e) => { setPriceAnnual(e.target.value); setMsg(''); }} />
            </div>
          </div>
        </div>
        <button className="c-btn c-btn-block" style={{ marginTop: 12 }} disabled={busy} onClick={() => void save()}>
          {busy ? 'Saving…' : 'Save prices'}
        </button>
      </div>
    </div></div>
  );
}
