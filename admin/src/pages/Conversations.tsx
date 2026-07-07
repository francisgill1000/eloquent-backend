import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { listConversations, renameConversation, deleteConversation, type Conversation } from '@/lib/assistant';

/** Compact, chat-app style timestamp: time today, "Yesterday", else "7 Jul". */
function whenLabel(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const now = new Date();
  if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const y = new Date(now);
  y.setDate(now.getDate() - 1);
  if (d.toDateString() === y.toDateString()) return 'Yesterday';
  return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
}

/**
 * "Chats" — the full-page list of the shop's past conversations with the Ask
 * assistant. Mirrors the history drawer inside the Ask page, but reachable
 * directly from the nav (below Home) so it works as a first-class destination
 * on both desktop and mobile. Open a row to resume it at /ask/:id.
 */
export default function Conversations() {
  const navigate = useNavigate();
  const [items, setItems] = useState<Conversation[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');

  useEffect(() => {
    let alive = true;
    listConversations()
      .then((c) => { if (alive) setItems(c); })
      .catch(() => { if (alive) setError('Could not load your chats.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  async function remove(id: number) {
    if (!window.confirm('Delete this chat?')) return;
    try { await deleteConversation(id); } catch { setError('Could not delete the chat.'); return; }
    setItems((t) => t.filter((c) => c.id !== id));
  }

  async function rename(id: number, current: string) {
    const next = window.prompt('Rename chat', current);
    if (next == null || !next.trim()) return;
    try { await renameConversation(id, next.trim()); } catch { setError('Could not rename the chat.'); return; }
    setItems((t) => t.map((c) => (c.id === id ? { ...c, title: next.trim() } : c)));
  }

  const q = query.trim().toLowerCase();
  const filtered = useMemo(
    () => (q ? items.filter((c) => (c.title || '').toLowerCase().includes(q)) : items),
    [items, q],
  );

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Chats</h1>
        <p className="c-page-sub">Your past conversations with the assistant.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {loading ? (
        <Spinner label="Loading chats…" />
      ) : items.length === 0 ? (
        <EmptyState
          icon={<Icons.Chat size={28} />}
          title="No chats yet"
          subtitle="Ask the assistant a question and your conversations show up here."
          action={<button className="c-btn" onClick={() => navigate('/ask')}>Ask something</button>}
        />
      ) : (
        <>
          <div className="c-input-row conv-search">
            <Icons.Search size={16} />
            <input
              type="search"
              placeholder="Search chats"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
          </div>

          {filtered.length === 0 ? (
            <EmptyState icon={<Icons.Search size={26} />} title="No matches" subtitle="Try a different search." />
          ) : (
            <div className="conv-list">
              {filtered.map((c) => (
                <div key={c.id} className="c-chat-row conv-row">
                  <button className="conv-main" onClick={() => navigate(`/ask/${c.id}`)}>
                    <span className="c-staff-avatar" aria-hidden="true"><Icons.Chat size={20} /></span>
                    <span className="conv-title">{c.title}</span>
                  </button>
                  <span className="conv-time">{whenLabel(c.updated_at)}</span>
                  <button className="c-icon-btn" aria-label="Rename chat" onClick={() => void rename(c.id, c.title)}><Icons.Edit size={16} /></button>
                  <button className="c-icon-btn" aria-label="Delete chat" onClick={() => void remove(c.id)}><Icons.Trash size={16} /></button>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div></div>
  );
}
