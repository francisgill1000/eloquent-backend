import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { QRCodeSVG, QRCodeCanvas } from 'qrcode.react';
import { AppBar } from '@/layout/AppBar';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { WHATSAPP_ENABLED } from '@/lib/features';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { updateShop, reverseGeocode } from '@/lib/shops';
import { fileToCompressedDataUrl } from '@/lib/image';

const CUSTOMER_WEB = 'https://bookings.eloquentservice.com';

type Form = {
  name: string;
  location: string;
  phone: string;
  email: string;
  lat: number | '';
  lon: number | '';
  logo: string | null;
  hero_image: string | null;
};

export default function Profile() {
  const navigate = useNavigate();
  const { shop, token, loginShop, logoutShop } = useShop();
  const [form, setForm] = useState<Form>({ name: '', location: '', phone: '', email: '', lat: '', lon: '', logo: null, hero_image: null });
  const [saving, setSaving] = useState(false);
  const [locating, setLocating] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [copied, setCopied] = useState(false);
  const [appCopied, setAppCopied] = useState(false);
  const logoInput = useRef<HTMLInputElement>(null);
  const heroInput = useRef<HTMLInputElement>(null);
  const qrCanvasWrap = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!shop) return;
    setForm({
      name: shop.name || '',
      location: shop.location || '',
      phone: shop.phone || '',
      email: shop.email || '',
      lat: (shop.latitude as number) ?? '',
      lon: (shop.longitude as number) ?? '',
      logo: null,
      hero_image: null,
    });
  }, [shop]);

  const change = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => ({ ...f, [key]: value }));

  const shopCode = (shop?.shop_code as string) || '';
  const pin = (shop?.pin as string) || '';
  const qrTarget = shop?.id ? `${CUSTOMER_WEB}/shop/${shop.id}` : '';
  // The admin app's own URL for the current environment (staging vs prod), so
  // scanning opens this same app on a phone to sign in with the business code + PIN.
  const appUrl = typeof window !== 'undefined' ? window.location.origin : '';
  const heroPreview = form.hero_image || (shop?.hero_image as string) || null;
  const logoPreview = form.logo || (shop?.logo as string) || null;

  const useMyLocation = () => {
    if (locating || !navigator.geolocation) { if (!navigator.geolocation) setError('Geolocation unavailable.'); return; }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      async ({ coords }) => {
        let location = form.location;
        try {
          const res = await reverseGeocode(coords.latitude, coords.longitude);
          if (res.address) location = res.address as string;
        } catch { /* use coords only */ }
        setForm((f) => ({ ...f, lat: coords.latitude, lon: coords.longitude, location }));
        setLocating(false);
      },
      () => { setError('Unable to fetch your location.'); setLocating(false); },
    );
  };

  const handleSave = async () => {
    if (!shop?.id) return;
    if (!form.name.trim()) { setError('Business name is required.'); return; }
    setSaving(true);
    setError('');
    setMessage('');
    try {
      const payload: Record<string, unknown> = {
        name: form.name, location: form.location, phone: form.phone, email: form.email,
      };
      if (form.lat !== '') payload.lat = Number(form.lat);
      if (form.lon !== '') payload.lon = Number(form.lon);
      if (form.logo) payload.logo = form.logo;
      if (form.hero_image) payload.hero_image = form.hero_image;
      const updated = await updateShop(shop.id, payload);
      if (updated?.id && token) loginShop(updated, token);
      setForm((f) => ({ ...f, logo: null, hero_image: null }));
      setMessage('Profile updated.');
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Failed to update profile.');
    } finally {
      setSaving(false);
    }
  };

  const shareQr = async () => {
    if (!qrTarget) return;
    const text = `Book ${shop?.name || 'us'} on Business Lens: ${qrTarget}`;
    if (navigator.share) {
      try { await navigator.share({ title: shop?.name || 'Business Lens', text, url: qrTarget }); } catch { /* dismissed */ }
    } else {
      void copyLink();
    }
  };

  // Compose a clean, printable PNG poster: white card, the QR, the shop
  // name and a call-to-action. Uses an offscreen logo-less canvas so the
  // export never taints (a cross-origin logo would block toDataURL).
  const downloadQr = () => {
    const src = qrCanvasWrap.current?.querySelector('canvas');
    if (!src) return;
    const qr = src.width;
    const pad = Math.round(qr * 0.08);
    const footer = Math.round(qr * 0.22);
    const W = qr + pad * 2;
    const H = qr + pad * 2 + footer;
    const c = document.createElement('canvas');
    c.width = W; c.height = H;
    const ctx = c.getContext('2d');
    if (!ctx) return;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);
    ctx.drawImage(src, pad, pad, qr, qr);
    ctx.textAlign = 'center';
    ctx.fillStyle = '#0e1714';
    ctx.font = `700 ${Math.round(qr * 0.07)}px Geist, system-ui, -apple-system, sans-serif`;
    ctx.fillText(shop?.name || 'Business Lens', W / 2, qr + pad * 2 + Math.round(footer * 0.42));
    ctx.fillStyle = '#00735c';
    ctx.font = `600 ${Math.round(qr * 0.045)}px Geist, system-ui, -apple-system, sans-serif`;
    ctx.fillText('Scan to book on Business Lens', W / 2, qr + pad * 2 + Math.round(footer * 0.78));
    const link = document.createElement('a');
    link.href = c.toDataURL('image/png');
    link.download = `${shopCode || shop?.name || 'admin'}-qr.png`.replace(/\s+/g, '-').toLowerCase();
    link.click();
  };

  const copyLink = async () => {
    if (!qrTarget) return;
    try {
      await navigator.clipboard.writeText(qrTarget);
      setCopied(true);
      setTimeout(() => setCopied(false), 1800);
    } catch { /* clipboard blocked */ }
  };

  const copyAppLink = async () => {
    if (!appUrl) return;
    try {
      await navigator.clipboard.writeText(appUrl);
      setAppCopied(true);
      setTimeout(() => setAppCopied(false), 1800);
    } catch { /* clipboard blocked */ }
  };

  const handleLogout = () => {
    if (window.confirm('Are you sure you want to sign out?')) { logoutShop(); navigate('/login'); }
  };

  return (
    <div className="m-screen c-profile">
      <AppBar title="Business Profile" actions={WHATSAPP_ENABLED ? <WhatsAppButton /> : undefined} />
      <div className="m-scroll">
        {error && <div className="c-error-box">{error}</div>}
        {message && <div className="c-card" style={{ color: 'var(--mint-300)' }}>{message}</div>}

        <div className="pf-grid">
          <div className="pf-col pf-col-form">
        <div className="pf-form">
          {/* Cover banner */}
          <button type="button" className="c-hero-pick" onClick={() => heroInput.current?.click()}>
            {heroPreview ? (
              <>
                <img src={heroPreview} alt="Cover banner" />
                {form.hero_image && <span className="c-media-badge">Preview</span>}
              </>
            ) : (
              <span className="c-hero-empty"><Icons.Grid size={22} /><span>Tap to add cover photo</span></span>
            )}
          </button>
          <input ref={heroInput} type="file" accept="image/*" hidden aria-label="Cover banner"
            onChange={async (e) => { const f = e.target.files?.[0]; if (f) change('hero_image', await fileToCompressedDataUrl(f, { maxDim: 1600 })); }} />

          {/* Logo */}
          <button type="button" className="c-logo-pick" onClick={() => logoInput.current?.click()}>
            {logoPreview ? <img src={logoPreview} alt="Logo" /> : <span className="c-logo-empty">Logo</span>}
            <span className="c-logo-cam"><Icons.Locate size={13} /></span>
          </button>
          <input ref={logoInput} type="file" accept="image/*" hidden aria-label="Logo"
            onChange={async (e) => { const f = e.target.files?.[0]; if (f) change('logo', await fileToCompressedDataUrl(f, { maxDim: 512 })); }} />

          <div style={{ height: 16 }} />

          <label className="c-field-label" htmlFor="name">Business Name</label>
          <div className="c-input-row">
            <input id="name" type="text" value={form.name} onChange={(e) => { change('name', e.target.value); setError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="location">Location</label>
          <div className="c-input-row">
            <input id="location" type="text" value={form.location} onChange={(e) => change('location', e.target.value)} />
          </div>
          <button className="c-btn-ghost" style={{ width: '100%', marginBottom: 16 }} disabled={locating} onClick={useMyLocation}>
            <Icons.Locate size={16} /> {locating ? 'Detecting…' : 'Use my location'}
          </button>

          <label className="c-field-label" htmlFor="phone">Phone</label>
          <div className="c-input-row">
            <input id="phone" type="tel" value={form.phone} onChange={(e) => change('phone', e.target.value)} />
          </div>

          <label className="c-field-label" htmlFor="email">Email</label>
          <div className="c-input-row">
            <input id="email" type="email" value={form.email} onChange={(e) => change('email', e.target.value)} />
          </div>

          <button className="c-btn c-btn-block" disabled={saving} onClick={() => void handleSave()}>
            {saving ? 'Saving…' : 'Save Profile'}
          </button>
        </div>
          </div>

          <div className="pf-col pf-col-side">
        {/* Credentials */}
        {(shopCode || pin) && (
          <>
            <div className="c-section-title">Credentials</div>
            <div className="c-cred-grid">
              <div className="c-cred">
                <div className="c-cred-label">Business Code</div>
                <div className="c-cred-value-row">
                  <span className="c-cred-value">{shopCode || '—'}</span>
                  <span className="c-cred-icon"><Icons.Tag size={16} /></span>
                </div>
              </div>
              <div className="c-cred">
                <div className="c-cred-label">Access PIN</div>
                <div className="c-cred-value-row">
                  <span className="c-cred-value">{pin || '—'}</span>
                  <span className="c-cred-icon"><Icons.Key size={16} /></span>
                </div>
              </div>
            </div>
          </>
        )}

        {/* App QR — scan to open this admin app on a phone and sign in. */}
        {appUrl && (
          <>
            <div className="c-section-title">App QR Code</div>
            <div className="c-card c-qr-card">
              <div className="c-qr-frame">
                <QRCodeSVG value={appUrl} size={188} level="M" bgColor="#ffffff" fgColor="#0a0e0c" />
              </div>
              <div className="c-qr-name">Open the app</div>
              <p className="c-qr-hint">Scan to open Business Lens on your phone, then sign in with your business code &amp; PIN.</p>
              <button className="c-btn-ghost" onClick={() => void copyAppLink()}>
                <Icons.Copy size={16} /> {appCopied ? 'Copied!' : 'Copy link'}
              </button>
            </div>
          </>
        )}

        {/* Business QR code — scan to view and book the business. */}
        {qrTarget && (
          <>
            <div className="c-section-title">Booking QR Code</div>
            <div className="c-card c-qr-card">
              <div className="c-qr-frame">
                <QRCodeSVG
                  value={qrTarget}
                  size={188}
                  level={logoPreview ? 'H' : 'M'}
                  bgColor="#ffffff"
                  fgColor="#0a0e0c"
                />
                {logoPreview && (
                  <span className="c-qr-logo">
                    <img src={logoPreview} alt="" />
                  </span>
                )}
              </div>
              <div className="c-qr-name">{shop?.name}</div>
              <p className="c-qr-hint">Customers scan to view and book your business.</p>
              <button className="c-btn c-btn-block" style={{ marginBottom: 10 }} onClick={downloadQr}>
                <Icons.Download size={16} /> Download QR
              </button>
              <div className="c-qr-actions">
                <button className="c-btn-ghost" onClick={() => void shareQr()}>
                  <Icons.Share size={16} /> Share
                </button>
                <button className="c-btn-ghost" onClick={() => void copyLink()}>
                  <Icons.Copy size={16} /> {copied ? 'Copied!' : 'Copy link'}
                </button>
              </div>
            </div>
            {/* Offscreen high-res canvas (no logo) used only for PNG export. */}
            <div ref={qrCanvasWrap} aria-hidden style={{ position: 'absolute', left: -99999, top: 0, pointerEvents: 'none' }}>
              <QRCodeCanvas value={qrTarget} size={1024} level="M" marginSize={2} bgColor="#ffffff" fgColor="#0a0e0c" />
            </div>
          </>
        )}
          </div>
        </div>

        <div className="pf-signout">
          <button className="c-btn-ghost" style={{ width: '100%', color: 'var(--danger)' }} onClick={handleLogout}>
            <Icons.Logout size={16} /> Sign Out
          </button>
        </div>
      </div>
    </div>
  );
}
