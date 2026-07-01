import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getMasterShops, updateMasterShop } from '@/lib/shops';
import { shortDate } from '@/lib/format';
import type { MasterShop } from '@/types';

function initial(name: string): string {
  const c = (name || '?').trim().charAt(0);
  return c ? c.toUpperCase() : '?';
}

export default function MasterShopDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const location = useLocation();
  const { shop: me } = useShop();

  const seeded = (location.state as { shop?: MasterShop } | null)?.shop;
  const [shop, setShop] = useState<MasterShop | null>(seeded ?? null);
  const [loading, setLoading] = useState(!seeded);
  const [persona, setPersona] = useState(seeded?.persona ?? '');
  const [savingPersona, setSavingPersona] = useState(false);
  const [personaSaved, setPersonaSaved] = useState(false);
  const [togglingStatus, setTogglingStatus] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (me && !me.is_master) { navigate('/'); return; }
    if (seeded) return; // already have it from the list
    let alive = true;
    getMasterShops()
      .then((list) => {
        if (!alive) return;
        const found = list.find((s) => String(s.id) === String(id)) ?? null;
        setShop(found);
        setPersona(found?.persona ?? '');
      })
      .catch(() => { if (alive) setError('Could not load this business.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [id, me, navigate, seeded]);

  const savePersona = async () => {
    if (!shop) return;
    setSavingPersona(true);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { persona });
      setShop(updated);
      setPersona(updated.persona ?? '');
      setPersonaSaved(true);
      setTimeout(() => setPersonaSaved(false), 1500);
    } catch {
      setError('Could not save the persona.');
    } finally {
      setSavingPersona(false);
    }
  };

  const toggleStatus = async () => {
    if (!shop) return;
    const next = shop.status === 'active' ? 'inactive' : 'active';
    const prev = shop.status;
    setShop({ ...shop, status: next }); // optimistic
    setTogglingStatus(true);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { status: next });
      setShop(updated);
    } catch {
      setShop({ ...shop, status: prev }); // revert
      setError('Could not change visibility.');
    } finally {
      setTogglingStatus(false);
    }
  };

  const copyCreds = async () => {
    if (!shop) return;
    try {
      await navigator.clipboard.writeText(`Business ID: ${shop.shop_code}\nPIN: ${shop.pin}`);
      setCopied(true);
      setTimeout(() => setCopied(false), 1200);
    } catch { /* values stay visible */ }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading…" /></div>;

  if (!shop) return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
      <p style={{ textAlign: 'center', color: 'var(--text-3)' }}>Business not found.</p>
    </div></div>
  );

  const inactive = shop.status !== 'active';
  const waHref = shop.phone
    ? `https://wa.me/${shop.phone.replace(/\D/g, '')}?text=${encodeURIComponent(
        `Your Admin login\nBusiness ID: ${shop.shop_code}\nPIN: ${shop.pin}`)}`
    : null;

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

      {error && <div className="c-error-box" style={{ margin: '0 16px 12px' }}>{error}</div>}

      <div className="c-msd-hero">
        <span className="c-msc-thumb" aria-hidden>{initial(shop.name)}</span>
        <div style={{ minWidth: 0 }}>
          <h1 className="c-page-title" style={{ fontSize: 20 }}>{shop.name}</h1>
          <p className="c-msd-sub">
            {shop.category || 'No category'}{shop.location ? ` · ${shop.location}` : ''}
          </p>
          <span className={`c-msc-wa${shop.wa_connected ? ' on' : ''}`}>
            <Icons.WhatsApp size={13} /> {shop.wa_connected ? 'Connected' : 'Not set up'}
          </span>
        </div>
      </div>

      <div className="c-msd-section">
        <h3 className="c-msd-h">Credentials</h3>
        <div className="c-master-creds">
          <span><b>ID</b> {shop.shop_code || '—'}</span>
          <span><b>PIN</b> {shop.pin || '—'}</span>
          <button className="c-icon-btn" aria-label="Copy credentials" onClick={() => void copyCreds()}>
            {copied ? <Icons.Check size={14} /> : <Icons.Copy size={14} />}
          </button>
        </div>
        {waHref && (
          <a className="c-btn-ghost c-msd-action" href={waHref} target="_blank" rel="noreferrer">
            <Icons.WhatsApp size={15} /> Send login to owner
          </a>
        )}
        <p className="c-msd-help">Send these login details to the owner.</p>
      </div>

      <div className="c-msd-section">
        <h3 className="c-msd-h">Visibility</h3>
        <button className={`c-btn-ghost c-msd-action${inactive ? ' off' : ''}`}
          disabled={togglingStatus} onClick={() => void toggleStatus()}>
          {inactive ? 'Show in customer app' : 'Hide from customer app'}
        </button>
        <p className="c-msd-help">
          {inactive
            ? 'Hidden from the customer app — customers can’t find or book this business.'
            : 'Visible in the customer app.'}
        </p>
      </div>

      <div className="c-msd-section">
        <h3 className="c-msd-h">Persona</h3>
        <label className="c-field-label" htmlFor="msd-persona">WhatsApp assistant persona</label>
        <div className="c-input-row c-input-area" style={{ marginBottom: 10 }}>
          <textarea id="msd-persona" rows={6} placeholder="Leave empty to use the default (based on the business category)…"
            value={persona} onChange={(e) => { setPersona(e.target.value); setPersonaSaved(false); }} />
        </div>
        <button className="c-btn c-btn-block" disabled={savingPersona} onClick={() => void savePersona()}>
          {savingPersona ? 'Saving…' : personaSaved ? 'Saved ✓' : 'Save persona'}
        </button>
        <p className="c-msd-help">Controls how the bot replies on this business’s own WhatsApp number.</p>
      </div>

      <div className="c-msd-section">
        <h3 className="c-msd-h">Activity</h3>
        <div className="c-master-meta">
          <span>{shop.bookings_count ?? 0} bookings</span>
          <span>Joined {shortDate(shop.created_at)}</span>
          <span>Last login {shortDate(shop.last_login_at)}</span>
        </div>
      </div>
    </div></div>
  );
}
