import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getWakeWord, saveWakeWord, type WakeWordInfo } from '@/lib/wakeWordApi';
import { matchesWakePhrase } from '@/lib/wakeWord';
import { speechRecognitionCtor, type SpeechRecognitionLike } from '@/lib/speechRecognition';

/** Turn a SpeechRecognition error code into copy the owner can act on. */
function describeSpeechError(code: string): string {
  if (code === 'not-allowed' || code === 'service-not-allowed') return 'Microphone access was blocked.';
  if (code === 'no-speech') return '';
  return 'Could not listen right now.';
}

/**
 * Sets the phrase the owner says on the AI Summary page to hear it read aloud.
 * Empty = fall back to the business's own name.
 */
export default function WakeWordSettings() {
  const navigate = useNavigate();
  const [info, setInfo] = useState<WakeWordInfo | null>(null);
  const [value, setValue] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  // Live "Test it" state.
  const [testing, setTesting] = useState(false);
  const [heard, setHeard] = useState('');
  const [testResult, setTestResult] = useState<'' | 'hit' | 'miss'>('');
  const [testError, setTestError] = useState('');
  const testErrorRef = useRef(''); // lets onend read the latest error without a stale closure
  const recRef = useRef<SpeechRecognitionLike | null>(null);
  const supported = speechRecognitionCtor() !== null;

  useEffect(() => {
    let alive = true;
    getWakeWord()
      .then((r) => { if (alive) { setInfo(r); setValue(r.phrase ?? ''); } })
      .catch(() => { if (alive) setError('Could not load your wake word.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; recRef.current?.stop(); };
  }, []);

  const save = async () => {
    setSaving(true); setError(''); setNotice('');
    const trimmed = value.trim();
    try {
      const r = await saveWakeWord(trimmed === '' ? null : trimmed);
      setInfo(r); setValue(r.phrase ?? ''); setNotice('Saved.');
    } catch {
      setError('Could not save. Please try again.');
    } finally { setSaving(false); }
  };

  // Listens for ~6s and reports whether what it heard would wake the summary.
  const test = () => {
    const Ctor = speechRecognitionCtor();
    if (!Ctor || testing) return;
    const target = value.trim() || info?.effective_phrase || '';
    setHeard(''); setTestResult(''); setTestError(''); testErrorRef.current = ''; setTesting(true);

    const rec = new Ctor();
    recRef.current = rec;
    rec.continuous = true;
    rec.interimResults = true;
    rec.lang = navigator.language || 'en-US';
    rec.onresult = (e) => {
      const text = Array.from(e.results).map((r) => r[0].transcript).join(' ');
      setHeard(text);
      if (matchesWakePhrase(text, target)) { setTestResult('hit'); rec.stop(); }
    };
    rec.onerror = (e) => {
      const message = describeSpeechError(e.error);
      testErrorRef.current = message;
      setTestError(message);
      setTesting(false);
    };
    rec.onend = () => {
      setTesting(false);
      if (testErrorRef.current) return; // a recorded error takes precedence over a miss
      setTestResult((prev) => (prev === 'hit' ? 'hit' : 'miss'));
    };
    try { rec.start(); } catch { setTesting(false); return; }
    window.setTimeout(() => { try { rec.stop(); } catch { /* already stopped */ } }, 6000);
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading wake word…" /></div>;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">Voice wake word</h1>
          <p className="c-page-sub">Say this on the AI Summary page to hear your summary read aloud, hands-free.</p>
        </div>
        <button className="c-icon-btn" aria-label="Back to settings" onClick={() => navigate('/settings')}><Icons.ChevronLeft size={18} /></button>
      </div>

      {error && <div className="c-error-box">{error}</div>}
      {notice && <div style={{ margin: '0 16px 12px', padding: 12, borderRadius: 'var(--r-md)', background: 'var(--mint-soft)', border: '1px solid var(--border-mint)', color: 'var(--mint-300)', fontSize: 13, textAlign: 'center' }}>{notice}</div>}

      <div style={{ padding: '0 16px 24px', display: 'flex', flexDirection: 'column', gap: 16 }}>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 12, color: 'var(--text-4)' }}>
          Wake phrase
          <input
            value={value}
            placeholder={info?.effective_phrase ?? ''}
            maxLength={60}
            onChange={(e) => { setValue(e.target.value); setNotice(''); setTestResult(''); setTestError(''); testErrorRef.current = ''; }}
            style={{ background: 'none', border: '1px solid var(--line, #333)', borderRadius: 8, color: 'var(--text-1)', padding: '10px 12px', font: 'inherit', fontSize: 15 }}
          />
        </label>

        <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-4)', lineHeight: 1.5 }}>
          Saying “hey” first is optional, and close-enough pronunciations still work — so
          “{info?.effective_phrase}” also wakes on “hey {info?.effective_phrase?.toLowerCase()}”.
          Leave this empty to use your business name.
        </p>

        {supported && (
          <div>
            <button className="c-btn-ghost" style={{ width: '100%' }} disabled={testing} onClick={test}>
              <Icons.Mic size={15} /> {testing ? 'Listening…' : 'Test it'}
            </button>
            {testError && (
              <div className="c-error-box" style={{ margin: '8px 0 0', padding: 8, fontSize: 12.5 }}>{testError}</div>
            )}
            {!testError && (heard || testResult) && (
              <p style={{ margin: '8px 4px 0', fontSize: 12.5, color: testResult === 'hit' ? 'var(--mint-300)' : 'var(--text-4)' }}>
                {heard ? `Heard: “${heard}”` : 'Heard nothing.'}
                {testResult === 'hit' && ' — that would wake it.'}
                {testResult === 'miss' && ' — that would not wake it.'}
              </p>
            )}
          </div>
        )}

        <button className="c-btn" disabled={saving} onClick={() => void save()}>{saving ? 'Saving…' : 'Save'}</button>
      </div>
    </div></div>
  );
}
