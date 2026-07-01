import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { getWaContacts } from '@/lib/chats';
import { LEAD_STATUSES, leadStatusDef } from '@/lib/leadStatus';
import { usePush } from '@/lib/usePush';
import type { WaContact } from '@/types';

const POLL_MS = 10000;

/** Short in-app chime (Web Audio — no asset needed) when a new lead arrives. */
function chime(): void {
  try {
    const Ctx = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
    const ctx = new Ctx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, ctx.currentTime);
    osc.frequency.setValueAtTime(1175, ctx.currentTime + 0.12);
    gain.gain.setValueAtTime(0.0001, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
    osc.start();
    osc.stop(ctx.currentTime + 0.36);
    osc.onended = () => void ctx.close();
  } catch { /* audio blocked until first interaction — ignore */ }
}

export function chatTime(iso?: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const now = new Date();
  const sameDay = d.toDateString() === now.toDateString();
  if (sameDay) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
  return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
}

export default function Chats() {
  const navigate = useNavigate();
  const [contacts, setContacts] = useState<WaContact[]>([]);
  const [connected, setConnected] = useState(true);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');
  // Open on Hot leads by default; tap "All" to see every chat (incl. new/untagged).
  const [statusFilter, setStatusFilter] = useState<string | null>('hot');
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  const prevUnread = useRef<number | null>(null);
  const push = usePush();

  useEffect(() => {
    let alive = true;
    const load = async (first = false) => {
      try {
        const res = await getWaContacts();
        if (!alive) return;
        setConnected(res.connected);
        setContacts(res.data);
        setError('');

        // Alert on a new unread message even when web-push doesn't show:
        // chime + a tab-title badge that's visible from other tabs.
        const unread = res.data.reduce((sum, c) => sum + (c.unread_count || 0), 0);
        if (prevUnread.current !== null && unread > prevUnread.current) chime();
        prevUnread.current = unread;
        document.title = unread > 0 ? `(${unread}) Admin` : 'Admin';
      } catch {
        if (alive && first) setError('Could not load chats.');
      } finally {
        if (alive) setLoading(false);
      }
    };
    void load(true);
    timer.current = setInterval(() => void load(), POLL_MS);
    return () => {
      alive = false;
      if (timer.current) clearInterval(timer.current);
      document.title = 'Admin';
    };
  }, []);

  const q = query.trim().toLowerCase();
  const filtered = contacts.filter((c) => {
    if (statusFilter && c.lead_status !== statusFilter) return false;
    if (q && !((c.name || '').toLowerCase().includes(q) || (c.wa_number || '').includes(q))) return false;
    return true;
  });

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Chats</h1>
        <p className="c-page-sub">WhatsApp and Live Chat conversations with your customers.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {push.supported && !push.on && (
        <div className="c-notif-banner">
          <Icons.Bell size={20} />
          <div className="txt">
            <div className="t1">Turn on notifications</div>
            <div className="t2">Get alerted the moment a new lead messages you.</div>
          </div>
          <button disabled={push.busy} onClick={() => void push.toggle()}>
            {push.busy ? '…' : 'Enable'}
          </button>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading chats…" />
      ) : !connected && contacts.length === 0 ? (
        <EmptyState
          icon={<Icons.WhatsApp size={28} />}
          title="WhatsApp not connected"
          subtitle="Connect your WhatsApp Business number to chat with customers here."
          action={
            <button className="c-btn" onClick={() => navigate('/chats/setup')}>
              Set up WhatsApp
            </button>
          }
        />
      ) : (
        <>
          <div className="c-input-row" style={{ margin: '0 16px 10px' }}>
            <input
              type="search"
              placeholder="Search name or number"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
          </div>

          <div className="c-pill-scroll" style={{ padding: '0 16px 12px' }}>
            {[{ value: null, label: 'All', dot: '', color: '#10b981' }, ...LEAD_STATUSES].map((s) => {
              const active = statusFilter === s.value;
              return (
                <button
                  key={s.value ?? 'all'}
                  type="button"
                  onClick={() => setStatusFilter(s.value)}
                  style={{
                    flex: '0 0 auto',
                    border: active ? `1px solid ${s.color}` : '1px solid rgba(255,255,255,0.14)',
                    background: active ? s.color : 'transparent',
                    color: active ? '#fff' : 'rgba(255,255,255,0.7)',
                    borderRadius: 999,
                    padding: '5px 11px',
                    fontSize: 12,
                    fontWeight: 600,
                    whiteSpace: 'nowrap',
                    cursor: 'pointer',
                  }}
                >
                  {s.dot ? `${s.dot} ` : ''}{s.label}
                </button>
              );
            })}
          </div>

          {filtered.length === 0 ? (
            <EmptyState
              icon={<Icons.Chat size={28} />}
              title={q || statusFilter ? 'No matches' : 'No chats yet'}
              subtitle={q || statusFilter ? 'Try a different search or filter.' : 'When customers message your WhatsApp number, chats appear here.'}
            />
          ) : (
            filtered.map((c) => {
              const name = c.name || c.wa_number || 'Live chat customer';
              const unread = c.unread_count || 0;
              const status = leadStatusDef(c.lead_status);
              return (
                <Link key={c.id} to={`/chats/${c.id}`} className="c-chat-row">
                  {/* Array.from: emoji-only profile names must not split surrogate pairs */}
                  <div className="c-staff-avatar">{(Array.from(name)[0] || '?').toUpperCase()}</div>
                  <div className="c-chat-row-body">
                    <div className="c-chat-row-top">
                      <span className="c-chat-row-name">
                        {status && <span title={status.label} style={{ marginRight: 5 }}>{status.dot}</span>}
                        {name}
                        {c.channel === 'app' && <span className="c-chan-badge">Live</span>}
                      </span>
                      <span className="c-chat-row-time">{chatTime(c.last_message_at)}</span>
                    </div>
                    <div className="c-chat-row-bottom">
                      <span className="c-chat-row-preview">
                        {c.last_message_direction === 'out' ? 'You: ' : ''}
                        {c.last_message_preview || ''}
                      </span>
                      {unread > 0 && <span className="c-chat-unread">{unread}</span>}
                    </div>
                  </div>
                </Link>
              );
            })
          )}
        </>
      )}
    </div></div>
  );
}
