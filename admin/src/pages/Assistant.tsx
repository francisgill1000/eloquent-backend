import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { generatePersona, getPersona, savePersona } from '@/lib/persona';

/**
 * The shop's AI assistant. The prompt in this box is the single source of
 * truth — it's sent to the model exactly as written, with no hidden additions.
 * "Generate from profile" fills it with a complete prompt built from the
 * shop's services, hours, staff and location, which the owner can then edit.
 */
export default function Assistant() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [draft, setDraft] = useState('');
  const [usingCustom, setUsingCustom] = useState(false);
  const [saving, setSaving] = useState(false);
  const [generating, setGenerating] = useState(false);

  useEffect(() => {
    let alive = true;
    getPersona()
      .then((info) => {
        if (!alive) return;
        // Show the saved prompt, or the generated default when none is saved.
        setDraft(info.persona ?? info.effective_prompt);
        setUsingCustom(info.using_custom);
      })
      .catch(() => { if (alive) setError('Could not load your assistant settings.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const apply = (persona: string | null) => {
    setSaving(true);
    setError('');
    setNotice('');
    return savePersona(persona)
      .then((info) => {
        setDraft(info.persona ?? info.effective_prompt);
        setUsingCustom(info.using_custom);
        setNotice(info.using_custom ? 'Saved — your assistant now uses this prompt.' : 'Cleared — using the generated default.');
      })
      .catch(() => setError('Could not save. Please try again.'))
      .finally(() => setSaving(false));
  };

  const generate = () => {
    if (draft.trim() && !window.confirm('Replace the current prompt with a fresh one generated from your profile?')) return;
    setGenerating(true);
    setError('');
    setNotice('');
    generatePersona()
      .then((prompt) => { setDraft(prompt); setNotice('Generated from your profile — review, edit, then Save.'); })
      .catch(() => setError('Could not generate. Please try again.'))
      .finally(() => setGenerating(false));
  };

  return (
    <div className="m-screen" style={{ height: '100dvh' }}>
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12, paddingTop: 18, flex: '0 0 auto' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">AI Assistant</h1>
          <p className="c-page-sub">
            This prompt is exactly what your assistant follows on WhatsApp and Live Chat — write
            your own, or generate one from your profile.
          </p>
        </div>
        <button className="c-icon-btn" aria-label="Back to settings" onClick={() => navigate('/settings')}>
          <Icons.ChevronLeft size={18} />
        </button>
      </div>

      {error && <div className="c-error-box">{error}</div>}
      {notice && (
        <div style={{ margin: '0 16px 12px', padding: 12, borderRadius: 'var(--r-md)', background: 'var(--mint-soft)', border: '1px solid var(--border-mint)', color: 'var(--mint-300)', fontSize: 13, textAlign: 'center' }}>
          {notice}
        </div>
      )}

      {loading ? (
        <Spinner label="Loading assistant…" />
      ) : (
        <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', padding: '0 16px calc(16px + env(safe-area-inset-bottom, 0px))' }}>
          <div className="c-field-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', margin: '0 4px 8px', flex: '0 0 auto' }}>
            <span>System prompt</span>
            <span style={{ color: usingCustom ? 'var(--mint-300)' : 'var(--text-4)', textTransform: 'none', letterSpacing: 0 }}>
              {usingCustom ? 'Custom' : 'Generated default'}
            </span>
          </div>

          {/* Editor fills all remaining height; long prompts scroll inside it. */}
          <div className="c-input-row c-input-area" style={{ flex: 1, minHeight: 0, marginBottom: 12 }}>
            <textarea
              aria-label="System prompt"
              placeholder="Write your assistant's instructions, or tap Generate from profile…"
              value={draft}
              onChange={(e) => { setDraft(e.target.value); setNotice(''); }}
              style={{ flex: 1, height: '100%', background: 'none', border: 'none', outline: 'none', color: 'var(--text-1)', font: 'inherit', fontSize: 13.5, lineHeight: 1.5, resize: 'none', overflowY: 'auto' }}
            />
          </div>

          <button className="c-btn-ghost" style={{ width: '100%', marginBottom: 10, flex: '0 0 auto', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8 }}
            disabled={generating || saving} onClick={() => generate()}>
            <Icons.Sparkle size={16} /> {generating ? 'Generating…' : 'Generate from profile'}
          </button>

          <button className="c-btn c-btn-block" style={{ flex: '0 0 auto' }} disabled={saving || !draft.trim()} onClick={() => void apply(draft)}>
            {saving ? 'Saving…' : 'Save prompt'}
          </button>

          {usingCustom && (
            <button className="c-btn-ghost" style={{ width: '100%', marginTop: 10, flex: '0 0 auto' }} disabled={saving}
              onClick={() => void apply(null)}>
              Clear &amp; use generated default
            </button>
          )}
        </div>
      )}
    </div>
  );
}
