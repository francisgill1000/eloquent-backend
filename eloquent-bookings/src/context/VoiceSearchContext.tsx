import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { aiSearch, type AiCategory, type AiChatMessage } from '@/lib/ai';
import { toggleFavourite } from '@/lib/shops';
import type { Shop } from '@/types';

export type AiMsg = {
  id: number;
  role: 'user' | 'ai';
  text: string;
  shops?: Shop[];
  categories?: AiCategory[];
  auth?: { mode: 'login' | 'register'; name?: string; phone?: string };
};

type VoiceSearch = {
  messages: AiMsg[];
  listening: boolean;
  sending: boolean;
  interim: string;
  supported: boolean;
  startListening: () => void;
  stopListening: () => void;
  toggleListening: () => void;
  send: (text: string) => void;
  favourite: (id: number) => void;
  signedIn: (name: string) => void;
};

const Ctx = createContext<VoiceSearch | null>(null);

/** Browser speech-to-text (Chrome/Edge/Safari). Undefined where unsupported. */
function getSpeechRecognition(): (new () => any) | undefined {
  if (typeof window === 'undefined') return undefined;
  return (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
}

/**
 * Holds the voice service-finder state so both the bottom-nav mic button and
 * the AI screen share it: the nav button triggers listening and shows the
 * pulse, the AI screen renders the resulting conversation.
 */
export function VoiceSearchProvider({ children }: { children: ReactNode }) {
  const [messages, setMessages] = useState<AiMsg[]>([]);
  const [listening, setListening] = useState(false);
  const [sending, setSending] = useState(false);
  const [interim, setInterim] = useState('');

  const navigate = useNavigate();

  const coordsRef = useRef<{ lat: number; lon: number } | undefined>(undefined);
  const recognitionRef = useRef<any>(null);
  const finalRef = useRef('');
  const nextId = useRef(1);
  const supported = !!getSpeechRecognition();

  // Best-effort location (once), so "near me" queries rank by distance.
  const ensureCoords = useCallback(() => {
    if (coordsRef.current || !navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
      (pos) => { coordsRef.current = { lat: pos.coords.latitude, lon: pos.coords.longitude }; },
      () => { /* denied — ignore */ },
      { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 },
    );
  }, []);

  const send = useCallback(async (text: string) => {
    const trimmed = text.trim();
    if (!trimmed) return;

    // Build the Anthropic-style thread from prior turns + this one. Skip turns
    // with no text (e.g. inline auth prompts).
    const history: AiChatMessage[] = messages
      .filter((m) => m.text.trim() !== '')
      .map((m) => ({ role: m.role === 'user' ? 'user' : 'assistant', content: m.text }));
    history.push({ role: 'user', content: trimmed });

    setMessages((prev) => [...prev, { id: nextId.current++, role: 'user', text: trimmed }]);
    setSending(true);
    try {
      const res = await aiSearch(history, coordsRef.current);

      const action = res.action ?? null;
      const auth = action && (action.type === 'login' || action.type === 'register')
        ? { mode: action.type, ...action.fields }
        : undefined;

      setMessages((prev) => [...prev, {
        id: nextId.current++,
        role: 'ai',
        text: res.reply,
        shops: res.shops?.length ? res.shops : undefined,
        categories: res.categories?.length ? res.categories : undefined,
        auth,
      }]);

      if (action?.type === 'navigate') navigate(action.route);
    } catch {
      setMessages((prev) => [...prev, { id: nextId.current++, role: 'ai', text: 'Something went wrong. Please try again.' }]);
    } finally {
      setSending(false);
    }
  }, [messages, navigate]);

  const stopListening = useCallback(() => { recognitionRef.current?.stop(); }, []);

  const startListening = useCallback(() => {
    const SR = getSpeechRecognition();
    if (!SR) return;
    ensureCoords();
    const rec = new SR();
    rec.lang = 'en-US';
    rec.interimResults = true;
    rec.continuous = false;
    rec.maxAlternatives = 1;
    finalRef.current = '';
    setInterim('');

    rec.onresult = (e: any) => {
      let it = '';
      for (let i = e.resultIndex; i < e.results.length; i++) {
        const t = e.results[i][0].transcript;
        if (e.results[i].isFinal) finalRef.current += t;
        else it += t;
      }
      setInterim((finalRef.current + ' ' + it).trim());
    };
    rec.onerror = () => { setListening(false); };
    rec.onend = () => {
      setListening(false);
      recognitionRef.current = null;
      const text = finalRef.current.trim();
      setInterim('');
      if (text) void send(text);
    };

    recognitionRef.current = rec;
    try { rec.start(); setListening(true); } catch { setListening(false); }
  }, [ensureCoords, send]);

  const toggleListening = useCallback(() => {
    if (recognitionRef.current) stopListening();
    else startListening();
  }, [startListening, stopListening]);

  const favourite = useCallback(async (id: number) => {
    setMessages((prev) => prev.map((m) => (m.shops
      ? { ...m, shops: m.shops.map((s) => (s.id === id ? { ...s, is_favourite: !s.is_favourite } : s)) }
      : m)));
    try { await toggleFavourite(id); } catch { /* optimistic — leave toggled */ }
  }, []);

  const signedIn = useCallback((name: string) => {
    setMessages((prev) => [...prev, { id: nextId.current++, role: 'ai', text: `✅ You're signed in${name ? `, ${name}` : ''}.` }]);
  }, []);

  return (
    <Ctx.Provider value={{ messages, listening, sending, interim, supported, startListening, stopListening, toggleListening, send, favourite, signedIn }}>
      {children}
    </Ctx.Provider>
  );
}

export function useVoiceSearch(): VoiceSearch {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useVoiceSearch must be used within VoiceSearchProvider');
  return ctx;
}
