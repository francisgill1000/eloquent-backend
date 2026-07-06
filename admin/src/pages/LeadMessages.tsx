import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getLeadMessages, saveLeadMessages } from '@/lib/leadMessages';

/**
 * Editable WhatsApp outreach templates for leads. `{name}` is replaced with the
 * lead's business name when the draft opens. Blank fields fall back to the
 * packaged defaults.
 */
export default function LeadMessages() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [opening, setOpening] = useState('');
  const [followup, setFollowup] = useState('');
  const [defaults, setDefaults] = useState({ opening: '', followup: '' });

  useEffect(() => {
    let alive = true;
    getLeadMessages()
      .then((m) => {
        if (!alive) return;
        setOpening(m.opening ?? m.default_opening);
        setFollowup(m.followup ?? m.default_followup);
        setDefaults({ opening: m.default_opening, followup: m.default_followup });
      })
      .catch(() => { if (alive) setError('Could not load your messages.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const save = () => {
    setSaving(true); setError(''); setNotice('');
    const norm = (v: string, def: string) => {
      const t = v.trim();
      return t === '' || t === def ? null : t;
    };
    saveLeadMessages(norm(opening, defaults.opening), norm(followup, defaults.followup))
      .then((m) => {
        setOpening(m.opening ?? m.default_opening);
        setFollowup(m.followup ?? m.default_followup);
        setNotice('Saved — new leads will use these messages.');
      })
      .catch(() => setError('Could not save. Please try again.'))
      .finally(() => setSaving(false));
  };

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">Lead messages</h1>
          <p className="c-page-sub">
            The WhatsApp messages drafted when you contact a lead. Use <code>{'{name}'}</code> for
            the lead's business name and <code>{'{shop}'}</code> for your own. Leave a message
            unchanged to keep using the default.
          </p>
        </div>
        <button className="c-icon-btn" aria-label="Back to settings" onClick={() => navigate('/settings')}>
          <Icons.ChevronLeft size={18} />
        </button>
      </div>

      {error && <div className="c-error-box">{error}</div>}
      {notice && (
        <div style={{ margin: '0 0 12px', padding: 12, borderRadius: 'var(--r-md)', background: 'var(--mint-soft)', border: '1px solid var(--border-mint)', color: 'var(--mint-300)', fontSize: 13, textAlign: 'center' }}>
          {notice}
        </div>
      )}

      {loading ? <Spinner label="Loading messages…" /> : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div>
            <div className="c-field-label" style={{ margin: '0 4px 8px' }}>Opening message</div>
            <div className="c-input-row c-input-area">
              <textarea
                aria-label="Opening message"
                value={opening}
                onChange={(e) => { setOpening(e.target.value); setNotice(''); }}
                rows={4}
                style={{ width: '100%', background: 'none', border: 'none', outline: 'none', color: 'var(--text-1)', font: 'inherit', fontSize: 13.5, lineHeight: 1.5, resize: 'vertical' }}
              />
            </div>
          </div>

          <div>
            <div className="c-field-label" style={{ margin: '0 4px 8px' }}>Follow-up message</div>
            <div className="c-input-row c-input-area">
              <textarea
                aria-label="Follow-up message"
                value={followup}
                onChange={(e) => { setFollowup(e.target.value); setNotice(''); }}
                rows={4}
                style={{ width: '100%', background: 'none', border: 'none', outline: 'none', color: 'var(--text-1)', font: 'inherit', fontSize: 13.5, lineHeight: 1.5, resize: 'vertical' }}
              />
            </div>
          </div>

          <button className="c-btn c-btn-block" disabled={saving} onClick={() => save()}>
            {saving ? 'Saving…' : 'Save messages'}
          </button>
        </div>
      )}
    </div></div>
  );
}
