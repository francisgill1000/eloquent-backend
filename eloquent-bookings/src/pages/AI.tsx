import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useVoiceSearch } from '@/context/VoiceSearchContext';
import { Icons } from '@/components/Icons';
import { ShopCard } from '@/components/ShopCard';
import { AuthInline } from '@/components/AuthInline';

export default function AI() {
  const navigate = useNavigate();
  const { messages, listening, sending, interim, supported, send, favourite, signedIn } = useVoiceSearch();
  const [typing, setTyping] = useState(!supported);
  const [draft, setDraft] = useState('');
  const scrollRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages, sending, interim]);

  const submit = () => {
    const t = draft.trim();
    if (!t) return;
    send(t);
    setDraft('');
  };

  const showEmpty = messages.length === 0 && !sending && !listening;

  return (
    <div className="m-screen ai-screen">
      <div className="c-thread-head">
        <div className="c-thread-avatar"><Icons.Sparkle size={20} /></div>
        <div className="c-thread-head-text">
          <span className="c-thread-title">AI Assistant</span>
          <span className="c-thread-sub">
            <span className="c-live-dot" />
            {sending ? 'thinking…' : listening ? 'listening…' : 'Voice service finder'}
          </span>
        </div>
        {supported && (
          <button className="c-icon-btn" style={{ marginLeft: 'auto' }} aria-label="Type instead" onClick={() => setTyping((t) => !t)}>
            <Icons.Keyboard size={18} />
          </button>
        )}
      </div>

      <div className="c-thread-scroll" ref={scrollRef}>
        {showEmpty && (
          <div className="c-ai-empty"><Icons.Mic size={36} /></div>
        )}
        {messages.map((m) => (
          <div key={m.id} className="c-ai-turn">
            <div className={`c-bubble ${m.role === 'user' ? 'out' : 'in'}`}>
              <span className="c-bubble-text">{m.text}</span>
            </div>
            {m.categories && m.categories.length > 0 && (
              <div className="c-ai-chips">
                {m.categories.map((c) => (
                  <button key={c.id} type="button" className="c-ai-chip" disabled={sending} onClick={() => send(c.name)}>
                    {c.name} <span className="c-ai-chip-count">{c.count}</span>
                  </button>
                ))}
              </div>
            )}
            {m.auth && (
              <AuthInline
                mode={m.auth.mode}
                name={m.auth.name}
                phone={m.auth.phone}
                onDone={(name) => signedIn(name)}
              />
            )}
            {m.shops && m.shops.length > 0 && (
              <div className="c-ai-results">
                {m.shops.map((s) => (
                  <ShopCard key={s.id} shop={s} onOpen={(id) => navigate(`/shop/${id}`)} onFavourite={favourite} />
                ))}
              </div>
            )}
          </div>
        ))}
        {sending && (
          <div className="c-bubble in"><span className="c-bubble-text">Thinking…</span></div>
        )}
      </div>

      {listening && (
        <div className="c-ai-listening">
          <span className="c-ai-listening-dot" />
          {interim || 'Listening…'}
        </div>
      )}

      {typing && (
        <div className="c-composer">
          <input
            type="text"
            placeholder="Ask me to find a service…"
            value={draft}
            autoFocus
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') submit(); }}
          />
          <button className="c-composer-send" aria-label="Send" disabled={!draft.trim()} onClick={submit}>
            <Icons.Send size={18} />
          </button>
        </div>
      )}
    </div>
  );
}
