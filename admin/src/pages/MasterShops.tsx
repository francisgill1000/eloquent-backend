import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getMasterShops } from '@/lib/shops';
import { getMasterPricing, updateMasterPricing } from '@/lib/masterPricing';
import { pushSupported, pushEnabled, enablePush, disablePush } from '@/lib/push';
import { MasterShopCard } from '@/components/MasterShopCard';
import type { MasterShop } from '@/types';

export default function MasterShops() {
  const navigate = useNavigate();
  const { shop, logoutShop } = useShop();
  const [shops, setShops] = useState<MasterShop[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');

  // browser push notifications (master only — gets every incoming WA message)
  const [pushOn, setPushOn] = useState(false);
  const [pushBusy, setPushBusy] = useState(false);

  // subscription pricing (AED strings in the inputs; stored as fils)
  const [priceMonthly, setPriceMonthly] = useState('');
  const [priceAnnual, setPriceAnnual] = useState('');
  const [pricingBusy, setPricingBusy] = useState(false);
  const [pricingMsg, setPricingMsg] = useState('');

  useEffect(() => {
    getMasterPricing()
      .then((p) => { setPriceMonthly(String(p.monthly / 100)); setPriceAnnual(String(p.annual / 100)); })
      .catch(() => undefined);
  }, []);

  const savePricing = async () => {
    setPricingBusy(true);
    setPricingMsg('');
    try {
      await updateMasterPricing({
        monthly_fils: Math.round(parseFloat(priceMonthly) * 100),
        annual_fils: Math.round(parseFloat(priceAnnual) * 100),
      });
      setPricingMsg('Saved ✓');
    } catch {
      setPricingMsg('Could not save prices.');
    } finally {
      setPricingBusy(false);
    }
  };

  useEffect(() => {
    if (pushSupported()) void pushEnabled().then(setPushOn).catch(() => undefined);
  }, []);

  const togglePush = async () => {
    setPushBusy(true);
    try {
      if (pushOn) {
        await disablePush();
        setPushOn(false);
      } else {
        await enablePush();
        setPushOn(true);
      }
    } catch (e) {
      alert((e as Error)?.message || 'Could not update notifications.');
    } finally {
      setPushBusy(false);
    }
  };

  useEffect(() => {
    // server enforces this too — the redirect is just UX
    if (shop && !shop.is_master) { navigate('/'); return; }
    let alive = true;
    getMasterShops()
      .then((list) => { if (alive) setShops(list); })
      .catch(() => { if (alive) setError('Could not load businesses.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [shop, navigate]);

  const q = query.trim().toLowerCase();
  const filtered = q
    ? shops.filter((s) =>
        s.name.toLowerCase().includes(q) ||
        (s.shop_code || '').includes(q) ||
        (s.phone || '').includes(q))
    : shops;

  return (
    <div className="m-screen c-master"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12, paddingTop: 18 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">All Businesses</h1>
          <p className="c-page-sub">Master view — credentials and activity for every account.</p>
        </div>
        {pushSupported() && (
          <button className="c-icon-btn" disabled={pushBusy}
            aria-label={pushOn ? 'Disable notifications' : 'Enable notifications'}
            title={pushOn ? 'Notifications on — tap to disable' : 'Get notified of new WhatsApp messages'}
            style={pushOn ? { color: '#22c55e' } : undefined}
            onClick={() => void togglePush()}>
            <Icons.Bell size={18} />
          </button>
        )}
        <button className="c-icon-btn" aria-label="Log out" onClick={() => { logoutShop(); navigate('/login'); }}>
          <Icons.Logout size={18} />
        </button>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      <div className="c-master-card" style={{ marginBottom: 14 }}>
        <div className="c-msc-top" style={{ marginBottom: 10 }}>
          <span className="c-master-name">Subscription pricing</span>
          {pricingMsg && <em style={{ fontSize: 12, color: 'var(--text-3)' }}>{pricingMsg}</em>}
        </div>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <div style={{ flex: 1, minWidth: 120 }}>
            <label className="c-field-label" htmlFor="price-monthly">Monthly (AED)</label>
            <div className="c-input-row">
              <input id="price-monthly" type="number" min="2" step="1" value={priceMonthly}
                onChange={(e) => { setPriceMonthly(e.target.value); setPricingMsg(''); }} />
            </div>
          </div>
          <div style={{ flex: 1, minWidth: 120 }}>
            <label className="c-field-label" htmlFor="price-annual">Annual (AED)</label>
            <div className="c-input-row">
              <input id="price-annual" type="number" min="2" step="1" value={priceAnnual}
                onChange={(e) => { setPriceAnnual(e.target.value); setPricingMsg(''); }} />
            </div>
          </div>
        </div>
        <button className="c-btn c-btn-block" style={{ marginTop: 12 }} disabled={pricingBusy} onClick={() => void savePricing()}>
          {pricingBusy ? 'Saving…' : 'Save prices'}
        </button>
      </div>

      <button className="c-btn-ghost" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6, width: 'calc(100% - 32px)', margin: '0 16px 12px' }}
        onClick={() => navigate('/master/new')}>
        <Icons.Plus size={15} /> Add business
      </button>

      <div className="c-input-row" style={{ margin: '0 16px 12px' }}>
        <input type="search" placeholder="Search name, code or phone" value={query}
          onChange={(e) => setQuery(e.target.value)} />
      </div>

      {loading ? (
        <Spinner label="Loading businesses…" />
      ) : filtered.length === 0 ? (
        <EmptyState title={q ? 'No matches' : 'No businesses yet'} />
      ) : (
        <div className="msc-grid">
          {filtered.map((s) => (
            <MasterShopCard
              key={s.id}
              shop={s}
              onOpen={(id) => navigate(`/master/${id}`, { state: { shop: s } })}
            />
          ))}
        </div>
      )}
    </div></div>
  );
}
