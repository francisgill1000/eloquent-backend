import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getWaContacts, getWaMessages, markWaRead, sendWaMessage, setWaAiEnabled, setWaLeadStatus } from '@/lib/chats';
import { LEAD_STATUSES } from '@/lib/leadStatus';
import type { WaContact, WaMessage } from '@/types';

const POLL_MS = 4000;

function bubbleTime(iso?: string): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

export default function ChatThread() {
  const navigate = useNavigate();
  const { id } = useParams();
  const contactId = Number(id);

  const [contact, setContact] = useState<WaContact | null>(null);
  const [messages, setMessages] = useState<WaMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [togglingAi, setTogglingAi] = useState(false);
  const [settingStatus, setSettingStatus] = useState(false);

  // Default on: a thread with no flag yet (older row) is still AI-handled.
  const aiOn = contact ? contact.ai_enabled !== false : true;

  const lastIdRef = useRef(0);
  const scrollRef = useRef<HTMLDivElement | null>(null);

  const appendMessages = useCallback((incoming: WaMessage[]) => {
    if (incoming.length === 0) return;
    setMessages((prev) => {
      const seen = new Set(prev.map((m) => m.id));
      const fresh = incoming.filter((m) => !seen.has(m.id));
      if (fresh.length === 0) return prev;
      return [...prev, ...fresh];
    });
    const maxId = Math.max(...incoming.map((m) => m.id));
    if (maxId > lastIdRef.current) lastIdRef.current = maxId;
  }, []);

  // initial load: contact info + history, mark read
  useEffect(() => {
    if (!contactId) return;
    let alive = true;
    (async () => {
      try {
        const [contactsRes, history] = await Promise.all([
          getWaContacts(),
          getWaMessages(contactId),
        ]);
        if (!alive) return;
        setContact(contactsRes.data.find((c) => c.id === contactId) ?? null);
        appendMessages(history);
        void markWaRead(contactId).catch(() => undefined);
      } catch {
        if (alive) setError('Could not load this chat.');
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => { alive = false; };
  }, [contactId, appendMessages]);

  // poll for new messages
  useEffect(() => {
    if (!contactId) return;
    const timer = setInterval(async () => {
      try {
        const fresh = await getWaMessages(contactId, lastIdRef.current);
        if (fresh.length > 0) {
          appendMessages(fresh);
          void markWaRead(contactId).catch(() => undefined);
        }
      } catch {
        /* transient poll error — next tick retries */
      }
    }, POLL_MS);
    return () => clearInterval(timer);
  }, [contactId, appendMessages]);

  // auto-scroll on new messages
  useEffect(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages.length]);

  const handleSend = async () => {
    const text = draft.trim();
    if (!text || sending) return;
    setSending(true);
    setError('');
    try {
      const sent = await sendWaMessage(contactId, text);
      appendMessages([sent]);
      setDraft('');
      // Sending as a human takes over the thread: the backend auto-pauses the
      // AI, so reflect that immediately rather than waiting for a reload.
      setContact((c) => (c && c.ai_enabled !== false ? { ...c, ai_enabled: false } : c));
    } catch (e) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg || (contact?.channel === 'app'
        ? 'Could not send. Please try again.'
        : 'Could not send. WhatsApp only allows free replies within 24h of the customer’s last message.'));
    } finally {
      setSending(false);
    }
  };

  const handleToggleAi = async () => {
    if (!contact || togglingAi) return;
    setTogglingAi(true);
    setError('');
    try {
      const updated = await setWaAiEnabled(contactId, !aiOn);
      setContact(updated);
    } catch {
      setError('Could not update AI mode. Please try again.');
    } finally {
      setTogglingAi(false);
    }
  };

  const handleSetStatus = async (status: string | null) => {
    if (!contact || settingStatus) return;
    setSettingStatus(true);
    setError('');
    try {
      const updated = await setWaLeadStatus(contactId, status);
      setContact(updated);
    } catch {
      setError('Could not update lead status. Please try again.');
    } finally {
      setSettingStatus(false);
    }
  };

  const title = contact?.name || contact?.wa_number || (contact?.channel === 'app' ? 'Live chat customer' : 'Chat');

  return (
    <div className="m-screen c-thread-screen">
      <div className="c-thread-head">
        <button className="c-icon-btn" aria-label="Back" onClick={() => navigate('/chats')}>
          <Icons.ChevronLeft size={18} />
        </button>
        <div className="c-staff-avatar" style={{ width: 38, height: 38, fontSize: 15 }}>
          {(Array.from(title)[0] || '?').toUpperCase()}
        </div>
        <div className="c-thread-head-text">
          <span className="c-thread-title">{title}</span>
          <span className="c-thread-sub">
            <span className="c-live-dot" />
            {contact?.channel === 'app'
              ? 'Live Chat — in the Booking Manager app'
              : contact?.name ? contact.wa_number : 'Live on WhatsApp'}
          </span>
        </div>
        <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8 }}>
          {contact?.wa_number && (
            <a
              className="c-icon-btn"
              href={`tel:+${contact.wa_number}`}
              aria-label={`Call +${contact.wa_number}`}
              title={`Call +${contact.wa_number}`}
              style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', textDecoration: 'none' }}
            >
              <Icons.Phone size={18} />
            </a>
          )}
          <button
            type="button"
            onClick={() => void handleToggleAi()}
            disabled={togglingAi || !contact}
            title={aiOn
              ? 'AI concierge is replying — tap to take over'
              : 'You’re handling this chat — tap to hand back to the AI'}
            style={{
              border: 'none',
              borderRadius: 999,
              padding: '6px 12px',
              fontSize: 12,
              fontWeight: 600,
              whiteSpace: 'nowrap',
              cursor: togglingAi || !contact ? 'default' : 'pointer',
              color: '#fff',
              background: aiOn ? '#10b981' : '#f59e0b',
              opacity: togglingAi ? 0.6 : 1,
            }}
          >
            {aiOn ? '🤖 AI' : '🧑 Human'}
          </button>
        </div>
      </div>

      <div
        className="c-pill-scroll"
        style={{
          padding: '8px 16px',
          borderBottom: '1px solid rgba(255,255,255,0.06)',
        }}
      >
        {LEAD_STATUSES.map((s) => {
          const active = contact?.lead_status === s.value;
          return (
            <button
              key={s.value}
              type="button"
              onClick={() => void handleSetStatus(active ? null : s.value)}
              disabled={!contact || settingStatus}
              title={active ? `Clear "${s.label}"` : `Mark as ${s.label}`}
              style={{
                flex: '0 0 auto',
                border: active ? `1px solid ${s.color}` : '1px solid rgba(255,255,255,0.14)',
                background: active ? s.color : 'transparent',
                color: active ? '#fff' : 'rgba(255,255,255,0.75)',
                borderRadius: 999,
                padding: '5px 11px',
                fontSize: 12,
                fontWeight: 600,
                whiteSpace: 'nowrap',
                cursor: !contact || settingStatus ? 'default' : 'pointer',
                opacity: settingStatus ? 0.6 : 1,
              }}
            >
              {s.dot} {s.label}
            </button>
          );
        })}
      </div>

      <div className="c-thread-scroll" ref={scrollRef}>
        {loading ? (
          <Spinner label="Loading messages…" />
        ) : messages.length === 0 ? (
          <p className="c-thread-empty">No messages yet.</p>
        ) : (
          messages.map((m) => {
            const isAudio = !!m.media_url && (m.type === 'audio' || m.type === 'voice');
            const isImage = !!m.media_url && m.type === 'image';
            return (
              <div key={m.id} className={`c-bubble ${m.direction === 'out' ? 'out' : 'in'}`}>
                {isAudio && <audio controls preload="none" src={m.media_url!} className="c-bubble-audio" />}
                {isImage && <img src={m.media_url!} alt="" className="c-bubble-img" loading="lazy" />}
                {!isAudio && !isImage && <span className="c-bubble-text">{m.body}</span>}
                <span className="c-bubble-time">{bubbleTime(m.created_at)}</span>
              </div>
            );
          })
        )}
      </div>

      {error && <div className="c-error-box" style={{ margin: '0 16px 8px' }}>{error}</div>}

      <div className="c-composer">
        <input
          type="text"
          placeholder="Type a reply…"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') void handleSend(); }}
        />
        <button
          className="c-composer-send"
          aria-label="Send"
          disabled={sending || !draft.trim()}
          onClick={() => void handleSend()}
        >
          <Icons.Send size={18} />
        </button>
      </div>
    </div>
  );
}
