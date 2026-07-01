import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getMasterShops, getServiceCategories, registerShop } from '@/lib/shops';
import { pushSupported, pushEnabled, enablePush, disablePush } from '@/lib/push';
import { MasterShopCard } from '@/components/MasterShopCard';
import type { MasterShop, ServiceCategory } from '@/types';

export default function MasterShops() {
  const navigate = useNavigate();
  const { shop, logoutShop } = useShop();
  const [shops, setShops] = useState<MasterShop[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');

  // create-business form
  const [showCreate, setShowCreate] = useState(false);
  const [categories, setCategories] = useState<ServiceCategory[]>([]);
  const [newName, setNewName] = useState('');
  const [newPhone, setNewPhone] = useState('');
  const [newCategoryId, setNewCategoryId] = useState('');
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState('');
  const [createdShop, setCreatedShop] = useState<{ name: string; code: string; pin: string } | null>(null);

  // browser push notifications (master only — gets every incoming WA message)
  const [pushOn, setPushOn] = useState(false);
  const [pushBusy, setPushBusy] = useState(false);

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
    getServiceCategories()
      .then((list) => { if (alive) setCategories(list); })
      .catch(() => undefined);
    return () => { alive = false; };
  }, [shop, navigate]);

  const handleCreate = async () => {
    if (!newName.trim()) { setCreateError('Business name is required.'); return; }
    if (!newPhone.trim()) { setCreateError('Phone number is required.'); return; }
    if (!newCategoryId) { setCreateError('Please choose a category.'); return; }
    setCreating(true);
    setCreateError('');
    try {
      const res = await registerShop({
        name: newName.trim(),
        phone: newPhone.trim(),
        category_id: Number(newCategoryId),
        is_verified: true,
      });
      setCreatedShop({
        name: res.shop?.name ?? newName.trim(),
        code: String(res.shop?.shop_code ?? ''),
        pin: String(res.shop?.pin ?? ''),
      });
      setNewName(''); setNewPhone(''); setNewCategoryId('');
      setShowCreate(false);
      setShops(await getMasterShops()); // refresh with the new business
    } catch (e) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setCreateError(msg || 'Could not create the business.');
    } finally {
      setCreating(false);
    }
  };

  const q = query.trim().toLowerCase();
  const filtered = q
    ? shops.filter((s) =>
        s.name.toLowerCase().includes(q) ||
        (s.shop_code || '').includes(q) ||
        (s.phone || '').includes(q))
    : shops;

  return (
    <div className="m-screen"><div className="m-scroll">
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

      {createdShop && (
        <div className="c-master-card" style={{ borderColor: 'var(--border-mint)' }}>
          <div className="c-master-top">
            <span className="c-master-name">{createdShop.name} <em>· created ✓</em></span>
            <button className="c-icon-btn" aria-label="Dismiss" onClick={() => setCreatedShop(null)}>
              <Icons.Check size={14} />
            </button>
          </div>
          <div className="c-master-creds">
            <span><b>ID</b> {createdShop.code}</span>
            <span><b>PIN</b> {createdShop.pin}</span>
            <button className="c-icon-btn" aria-label="Copy new credentials"
              onClick={() => void navigator.clipboard.writeText(`Business ID: ${createdShop.code}\nPIN: ${createdShop.pin}`).catch(() => undefined)}>
              <Icons.Copy size={14} />
            </button>
          </div>
          <div className="c-master-meta"><span>Send these login details to the owner.</span></div>
        </div>
      )}

      <button className="c-btn-ghost" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6, width: 'calc(100% - 32px)', margin: '0 16px 12px' }}
        onClick={() => { setShowCreate((v) => !v); setCreateError(''); }}>
        <Icons.Plus size={15} /> {showCreate ? 'Cancel' : 'Add business'}
      </button>

      {showCreate && (
        <div className="c-master-card" style={{ marginBottom: 14 }}>
          {createError && <div className="c-error-box" style={{ margin: '0 0 12px' }}>{createError}</div>}

          <label className="c-field-label" htmlFor="mb-name">Business Name</label>
          <div className="c-input-row" style={{ marginBottom: 12 }}>
            <input id="mb-name" type="text" placeholder="e.g. Glow Salon" value={newName}
              onChange={(e) => { setNewName(e.target.value); setCreateError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-phone">Phone Number</label>
          <div className="c-input-row" style={{ marginBottom: 12 }}>
            <input id="mb-phone" type="tel" placeholder="+9715xxxxxxxx" value={newPhone}
              onChange={(e) => { setNewPhone(e.target.value); setCreateError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-category">Service Category</label>
          <div className="c-input-row" style={{ marginBottom: 14 }}>
            <select id="mb-category" value={newCategoryId}
              onChange={(e) => { setNewCategoryId(e.target.value); setCreateError(''); }}>
              <option value="" disabled>Choose category…</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>

          <button className="c-btn c-btn-block" disabled={creating} onClick={() => void handleCreate()}>
            {creating ? 'Creating…' : 'Create Business'}
          </button>
        </div>
      )}

      <div className="c-input-row" style={{ margin: '0 16px 12px' }}>
        <input type="search" placeholder="Search name, code or phone" value={query}
          onChange={(e) => setQuery(e.target.value)} />
      </div>

      {loading ? (
        <Spinner label="Loading businesses…" />
      ) : filtered.length === 0 ? (
        <EmptyState title={q ? 'No matches' : 'No businesses yet'} />
      ) : (
        filtered.map((s) => (
          <MasterShopCard
            key={s.id}
            shop={s}
            onOpen={(id) => navigate(`/master/${id}`, { state: { shop: s } })}
          />
        ))
      )}
    </div></div>
  );
}
