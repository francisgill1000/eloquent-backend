import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getShop, updateShop } from '@/lib/shops';

type Settings = {
  booking_reminders_enabled: boolean;
  booking_reminder_template: string;
  booking_reviews_enabled: boolean;
  review_request_template: string;
  google_review_url: string;
  waitlist_notify_enabled: boolean;
  waitlist_notify_template: string;
};

const EMPTY: Settings = {
  booking_reminders_enabled: true,
  booking_reminder_template: '',
  booking_reviews_enabled: true,
  review_request_template: '',
  google_review_url: '',
  waitlist_notify_enabled: true,
  waitlist_notify_template: '',
};

const REMINDER_PLACEHOLDER =
  'Hi {name}, this is a reminder of your appointment at {shop} on {date} at {time}. Reply here to confirm or reschedule.';
const REVIEW_PLACEHOLDER =
  'Hi {name}, thanks for visiting {shop}! How was your experience? Tap to leave a quick rating: {link}';
const WAITLIST_PLACEHOLDER =
  'Good news {name}! A slot opened up at {shop} and your booking {reference} is confirmed for {date} at {time}. See you then!';

function Toggle({ on, onClick, label, sub }: { on: boolean; onClick: () => void; label: string; sub: string }) {
  return (
    <div className="c-set-link" role="button" tabIndex={0} aria-pressed={on}
      style={{ cursor: 'pointer' }}
      onClick={onClick}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } }}>
      <span className="c-set-body">
        <span className="c-set-label">{label}</span>
        <span className="c-set-sub">{sub}</span>
      </span>
      <span className={`c-toggle ${on ? 'on' : ''}`}><span className="c-toggle-knob" /></span>
    </div>
  );
}

export default function BookingNotifications() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const [s, setS] = useState<Settings>(EMPTY);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!shop?.id) return;
    getShop(shop.id)
      .then((d) => {
        const x = d as Record<string, unknown>;
        const str = (k: string) => (typeof x[k] === 'string' ? (x[k] as string) : '');
        const bool = (k: string) => x[k] !== false && x[k] !== 0 && x[k] !== '0';
        setS({
          booking_reminders_enabled: bool('booking_reminders_enabled'),
          booking_reminder_template: str('booking_reminder_template'),
          booking_reviews_enabled: bool('booking_reviews_enabled'),
          review_request_template: str('review_request_template'),
          google_review_url: str('google_review_url'),
          waitlist_notify_enabled: bool('waitlist_notify_enabled'),
          waitlist_notify_template: str('waitlist_notify_template'),
        });
      })
      .catch(() => setError('Could not load settings.'))
      .finally(() => setLoading(false));
  }, [shop?.id]);

  const set = <K extends keyof Settings>(k: K, v: Settings[K]) => { setS((p) => ({ ...p, [k]: v })); setSaved(false); };

  const save = async () => {
    if (!shop?.id) return;
    setSaving(true);
    setError('');
    try {
      await updateShop(shop.id, s as unknown as Record<string, unknown>);
      setSaved(true);
    } catch {
      setError('Could not save settings.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading settings…" /></div>;

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate('/settings')}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Booking notifications</h1>
        <p className="c-page-sub">Automatic WhatsApp messages to your customers. These only send when your WhatsApp account is connected.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      <div className="c-set-grid" style={{ gap: 18 }}>
        {/* Reminders */}
        <Toggle on={s.booking_reminders_enabled} onClick={() => set('booking_reminders_enabled', !s.booking_reminders_enabled)}
          label="Appointment reminders" sub="Remind customers 24h before their appointment" />
        {s.booking_reminders_enabled && (
          <div className="c-input-row" style={{ alignItems: 'flex-start' }}>
            <textarea rows={3} placeholder={REMINDER_PLACEHOLDER} value={s.booking_reminder_template}
              onChange={(e) => set('booking_reminder_template', e.target.value)}
              style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', resize: 'vertical', font: 'inherit' }} />
          </div>
        )}

        {/* Reviews */}
        <Toggle on={s.booking_reviews_enabled} onClick={() => set('booking_reviews_enabled', !s.booking_reviews_enabled)}
          label="Review requests" sub="Ask for a rating after a completed booking; 4–5★ are sent to Google" />
        {s.booking_reviews_enabled && (
          <>
            <label className="c-field-label">Google review link (for 4–5★ ratings)</label>
            <div className="c-input-row">
              <input type="url" placeholder="https://g.page/your-business/review" value={s.google_review_url}
                onChange={(e) => set('google_review_url', e.target.value)} />
            </div>
            <div className="c-input-row" style={{ alignItems: 'flex-start' }}>
              <textarea rows={3} placeholder={REVIEW_PLACEHOLDER} value={s.review_request_template}
                onChange={(e) => set('review_request_template', e.target.value)}
                style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', resize: 'vertical', font: 'inherit' }} />
            </div>
          </>
        )}

        {/* Waitlist */}
        <Toggle on={s.waitlist_notify_enabled} onClick={() => set('waitlist_notify_enabled', !s.waitlist_notify_enabled)}
          label="Waitlist confirmations" sub="Tell a waitlisted customer when their slot is confirmed" />
        {s.waitlist_notify_enabled && (
          <div className="c-input-row" style={{ alignItems: 'flex-start' }}>
            <textarea rows={3} placeholder={WAITLIST_PLACEHOLDER} value={s.waitlist_notify_template}
              onChange={(e) => set('waitlist_notify_template', e.target.value)}
              style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', resize: 'vertical', font: 'inherit' }} />
          </div>
        )}
      </div>

      <p className="c-page-sub" style={{ marginTop: 12 }}>
        Placeholders: <code>{'{name}'}</code> <code>{'{shop}'}</code> <code>{'{date}'}</code> <code>{'{time}'}</code> <code>{'{link}'}</code> <code>{'{reference}'}</code>
      </p>

      <button className="c-btn c-btn-block" disabled={saving} onClick={() => void save()} style={{ marginTop: 8 }}>
        {saving ? 'Saving…' : saved ? 'Saved ✓' : 'Save changes'}
      </button>
    </div></div>
  );
}
