import { useEffect, useState } from 'react';
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
 * assistant. Reachable directly from the nav (below Home) on desktop and
 * mobile. Loads 20 at a time via "Load more" and searches server-side so the
 * query spans every page, not just the loaded ones. Open a row to resume it.
 */
export default function Conversations() {
  const navigate = useNavigate();
  const [items, setItems] = useState<Conversation[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);      // initial load + search reloads
  const [loadingMore, setLoadingMore] = useState(false);
  const [booted, setBooted] = useState(false);       // first response has landed
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');

  // First load, then a debounced server-side search whenever the query changes.
  useEffect(() => {
    let alive = true;
    setLoading(true);
    setError('');
    const t = setTimeout(() => {
      listConversations({ page: 1, q: query })
        .then((res) => {
          if (!alive) return;
          setItems(res.conversations);
          setHasMore(res.has_more);
          setPage(1);
        })
        .catch(() => { if (alive) setError('Could not load your chats.'); })
        .finally(() => { if (alive) { setLoading(false); setBooted(true); } });
    }, booted ? 300 : 0); // instant first load; debounce subsequent keystrokes
    return () => { alive = false; clearTimeout(t); };
  }, [query]); // eslint-disable-line react-hooks/exhaustive-deps

  async function loadMore() {
    const next = page + 1;
    setLoadingMore(true);
    try {
      const res = await listConversations({ page: next, q: query });
      setItems((cur) => [...cur, ...res.conversations]);
      setHasMore(res.has_more);
      setPage(next);
    } catch { setError('Could not load more chats.'); }
    finally { setLoadingMore(false); }
  }

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

  const searching = query.trim() !== '';
  // The shop has no chats at all (not merely a search miss): hide the search box.
  const noChatsAtAll = booted && !loading && !searching && items.length === 0;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Chats</h1>
        <p className="c-page-sub">Your past conversations with the assistant.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {!booted ? (
        <Spinner label="Loading chats…" />
      ) : noChatsAtAll ? (
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

          {loading ? (
            <Spinner label="Searching…" />
          ) : items.length === 0 ? (
            <EmptyState icon={<Icons.Search size={26} />} title="No matches" subtitle="Try a different search." />
          ) : (
            <div className="conv-list">
              {items.map((c) => (
                <div key={c.id} className="c-chat-row conv-row">
                  <button className="conv-main" onClick={() => navigate(`/ask/${c.id}`)}>
                    <span className="c-staff-avatar" aria-hidden="true">
                      {c.source === 'customer' ? <Icons.Store size={20} /> : <Icons.Chat size={20} />}
                    </span>
                    <span className="conv-title">
                      {c.title}
                      {c.source === 'customer' && <span className="conv-badge">Customer</span>}
                    </span>
                  </button>
                  <span className="conv-time">{whenLabel(c.updated_at)}</span>
                  <button className="c-icon-btn" aria-label="Rename chat" onClick={() => void rename(c.id, c.title)}><Icons.Edit size={16} /></button>
                  <button className="c-icon-btn" aria-label="Delete chat" onClick={() => void remove(c.id)}><Icons.Trash size={16} /></button>
                </div>
              ))}
              {hasMore && (
                <button className="conv-more" onClick={() => void loadMore()} disabled={loadingMore}>
                  {loadingMore ? 'Loading…' : 'Load more'}
                </button>
              )}
            </div>
          )}
        </>
      )}
    </div></div>
  );
}
