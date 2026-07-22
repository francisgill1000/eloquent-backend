import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getMasterShops, updateMasterShop, grantShopCredits } from '@/lib/shops';
import { shortDate } from '@/lib/format';
import { shopHasModule, type Module } from '@/lib/modules';
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
  const [togglingModule, setTogglingModule] = useState<Module | null>(null);
  const [creditAmount, setCreditAmount] = useState('');
  const [grantingCredits, setGrantingCredits] = useState(false);
  const [creditMsg, setCreditMsg] = useState('');
  const [togglingSelfServe, setTogglingSelfServe] = useState(false);
  const [email, setEmail] = useState(seeded?.email ?? '');
  const [savingEmail, setSavingEmail] = useState(false);
  const [emailSaved, setEmailSaved] = useState(false);
  const [newPassword, setNewPassword] = useState('');
  const [settingPassword, setSettingPassword] = useState(false);
  const [passwordMsg, setPasswordMsg] = useState('');
  const [justSetPassword, setJustSetPassword] = useState('');
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
        setEmail(found?.email ?? '');
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

  const toggleModule = async (module: Module) => {
    if (!shop) return;
    const current = (shop.modules ?? ['bookings']) as Module[];
    const next = current.includes(module)
      ? current.filter((m) => m !== module)
      : [...current, module];
    const prev = shop.modules;
    setShop({ ...shop, modules: next }); // optimistic
    setTogglingModule(module);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { modules: next });
      setShop(updated);
    } catch {
      setShop({ ...shop, modules: prev }); // revert
      setError('Could not change modules.');
    } finally {
      setTogglingModule(null);
    }
  };

  const grantCredits = async () => {
    if (!shop) return;
    const amt = Math.round(parseFloat(creditAmount));
    if (!amt || amt < 1) { setCreditMsg('Enter a positive amount.'); return; }
    setGrantingCredits(true);
    setCreditMsg('');
    try {
      const { credits } = await grantShopCredits(shop.id, amt);
      setShop({ ...shop, hunt_credits: credits });
      setCreditAmount('');
      setCreditMsg(`Granted ✓ — balance now ${credits.toLocaleString('en-AE')}`);
    } catch {
      setCreditMsg('Could not grant credits.');
    } finally {
      setGrantingCredits(false);
    }
  };

  const toggleSelfServe = async () => {
    if (!shop) return;
    const next = !shop.hunt_self_serve;
    const prev = shop.hunt_self_serve;
    setShop({ ...shop, hunt_self_serve: next }); // optimistic
    setTogglingSelfServe(true);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { hunt_self_serve: next });
      setShop(updated);
    } catch {
      setShop({ ...shop, hunt_self_serve: prev }); // revert
      setError('Could not change self-serve.');
    } finally {
      setTogglingSelfServe(false);
    }
  };

  const saveEmail = async () => {
    if (!shop || !email.trim()) return;
    setSavingEmail(true);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { email: email.trim() });
      setShop(updated);
      setEmail(updated.email ?? '');
      setEmailSaved(true);
      setTimeout(() => setEmailSaved(false), 1500);
    } catch {
      setError('Could not save the email.');
    } finally {
      setSavingEmail(false);
    }
  };

  const setShopPassword = async () => {
    if (!shop) return;
    const pwd = newPassword.trim();
    if (pwd.length < 8) { setPasswordMsg('Password must be at least 8 characters.'); return; }
    setSettingPassword(true);
    setPasswordMsg('');
    try {
      await updateMasterShop(shop.id, { password: pwd });
      setJustSetPassword(pwd);
      setNewPassword('');
      setPasswordMsg('Password set ✓ — copy it below before leaving this page.');
    } catch {
      setPasswordMsg('Could not set the password.');
    } finally {
      setSettingPassword(false);
    }
  };

  const copyPassword = async () => {
    if (!justSetPassword) return;
    try {
      await navigator.clipboard.writeText(justSetPassword);
      setCopied(true);
      setTimeout(() => setCopied(false), 1200);
    } catch { /* value stays visible */ }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading…" /></div>;

  if (!shop) return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
      <p style={{ textAlign: 'center', color: 'var(--text-3)' }}>Business not found.</p>
    </div></div>
  );

  const inactive = shop.status !== 'active';

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
        <label className="c-field-label" htmlFor="msd-email">Email</label>
        <div className="c-input-row" style={{ marginBottom: 10 }}>
          <input id="msd-email" type="email" value={email}
            onChange={(e) => { setEmail(e.target.value); setEmailSaved(false); }} />
        </div>
        <button className="c-btn-ghost c-msd-action" disabled={savingEmail || !email.trim()} onClick={() => void saveEmail()}>
          {savingEmail ? 'Saving…' : emailSaved ? 'Saved ✓' : 'Save email'}
        </button>

        <label className="c-field-label" htmlFor="msd-password" style={{ marginTop: 14, display: 'block' }}>New password</label>
        <div className="c-input-row" style={{ marginBottom: 10 }}>
          <input id="msd-password" type="text" placeholder="At least 8 characters" value={newPassword}
            onChange={(e) => { setNewPassword(e.target.value); setPasswordMsg(''); }} />
        </div>
        <button className="c-btn-ghost c-msd-action" disabled={settingPassword || newPassword.trim().length < 8}
          onClick={() => void setShopPassword()}>
          {settingPassword ? 'Setting…' : 'Set password'}
        </button>
        {passwordMsg && <p className="c-msd-help">{passwordMsg}</p>}

        {justSetPassword && (
          <div className="c-master-creds" style={{ marginTop: 10 }}>
            <span><b>Password</b> {justSetPassword}</span>
            <button className="c-icon-btn" aria-label="Copy password" onClick={() => void copyPassword()}>
              {copied ? <Icons.Check size={14} /> : <Icons.Copy size={14} />}
            </button>
          </div>
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
        <h3 className="c-msd-h">Modules</h3>
        {([
          ['bookings', 'Bookings', 'Calendar, services, staff & working hours'],
          ['leads', 'Business Hunt', 'Find & work B2B leads'],
        ] as [Module, string, string][]).map(([key, label, sub]) => {
          const on = shopHasModule(shop, key);
          return (
            <button key={key}
              className={`c-btn-ghost c-msd-action${on ? '' : ' off'}`}
              disabled={togglingModule === key}
              onClick={() => void toggleModule(key)}>
              {on ? `Disable ${label}` : `Enable ${label}`}
              <span className="c-msd-help" style={{ display: 'block' }}>{sub}</span>
            </button>
          );
        })}
        <p className="c-msd-help">Controls which menus this business sees in the app.</p>
      </div>

      <div className="c-msd-section">
        <h3 className="c-msd-h">Business Hunt credits</h3>
        <p className="c-msd-help" style={{ marginTop: 0 }}>
          Balance: <b style={{ color: 'var(--text-1)' }}>{(shop.hunt_credits ?? 0).toLocaleString('en-AE')}</b> credits
          &nbsp;·&nbsp; 1 credit = 1 live business search. Independent of the subscription.
        </p>
        <div className="c-input-row" style={{ marginBottom: 8 }}>
          <input type="number" min="1" step="1" placeholder="Amount to grant (e.g. 200)"
            value={creditAmount}
            onChange={(e) => { setCreditAmount(e.target.value); setCreditMsg(''); }} />
        </div>
        <button className="c-btn c-btn-block" disabled={grantingCredits || !creditAmount.trim()}
          onClick={() => void grantCredits()}>
          {grantingCredits ? 'Granting…' : 'Grant credits'}
        </button>
        {creditMsg && <p className="c-msd-help">{creditMsg}</p>}
        <p className="c-msd-help">Add credits after selling a pack by hand (Ziina link).</p>

        <button className={`c-btn-ghost c-msd-action${shop.hunt_self_serve ? '' : ' off'}`}
          style={{ marginTop: 10 }}
          disabled={togglingSelfServe} onClick={() => void toggleSelfServe()}>
          {shop.hunt_self_serve ? 'Disable self-serve purchase' : 'Enable self-serve purchase'}
          <span className="c-msd-help" style={{ display: 'block' }}>
            Simulated — lets this shop buy packs in-app and get credits with no real payment.
          </span>
        </button>
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
