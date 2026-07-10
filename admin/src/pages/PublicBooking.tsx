import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { AudioBubble, ThinkingBubble, renderContent } from '@/components/chat';
import { getPublicShop, bookAssistantText, bookAssistantVoice, recordBooking, type AssistantReply, type BookingFields, type PublicShop, type Turn } from '@/lib/publicBooking';
import { createBooking } from '@/lib/bookings';
import { useRecorder } from '@/hooks/useRecorder';
import { newBookingSession } from '@/lib/bookingSession';
import { speak } from '@/lib/simulation';
import '@/styles/public-booking.css';

type Created = { service: string; date: string; start_time: string; customer_name: string; reference: string };
type Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null };

function todayIso(): string {
  const d = new Date();
  const y = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${mm}-${dd}`;
}

// Normalise a spoken/typed number to a canonical UAE mobile (05XXXXXXXX), or
// null if it isn't a valid UAE mobile. Lenient on input, strict on result.
function canonicalUaeMobile(raw?: string): string | null {
  let d = (raw || '').replace(/\D+/g, '');
  if (d.startsWith('971')) d = d.slice(3);
  if (d.length === 9 && d.startsWith('5')) d = '0' + d;
  return /^05\d{8}$/.test(d) ? d : null;
}

// Base64 MP3 -> object URL for a voice-note bubble. Returns null (instead of
// throwing) on malformed input, so a bad reply_audio doesn't sink an
// otherwise-valid reply.
function base64ToBlobUrl(b64: string): string | null {
  try {
    const bin = atob(b64);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return URL.createObjectURL(new Blob([bytes], { type: 'audio/mpeg' }));
  } catch {
    return null;
  }
}

// Tap to record; taps again to send. Safety net: after speaking, this much
// quiet auto-sends (measured on the real audio level, so a mid-sentence pause
// won't trip it).
const AUTO_STOP_MS = 1500;

/**
 * Public, voice-or-text self-service booking, styled as the admin Ask chat.
 * The booking flow is unchanged; only the presentation is the conversational
 * thread. A "+" starts a fresh, separate thread.
 */
export default function PublicBooking() {
  const { shopId } = useParams<{ shopId: string }>();
  const id = Number(shopId);

  const [shop, setShop] = useState<PublicShop | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [created, setCreated] = useState<Created | null>(null);
  const [busy, setBusy] = useState(false);
  const [speaking, setSpeaking] = useState(false);
  const [micDenied, setMicDenied] = useState(false);
  const [messages, setMessages] = useState<Msg[]>([]);
  const [draft, setDraft] = useState('');

  const fieldsRef = useRef<BookingFields>({ date: todayIso() });
  const historyRef = useRef<Turn[]>([]);
  const bookedRef = useRef(false);
  const playCtxRef = useRef<AudioContext | null>(null);
  const playSrcRef = useRef<AudioBufferSourceNode | null>(null);
  const finishingRef = useRef(false);
  const threadRef = useRef<HTMLDivElement>(null);
  const audioUrlsRef = useRef<string[]>([]);   // object URLs to revoke
  const epochRef = useRef(0);   // bumped by newBooking() to invalidate in-flight turns

  const { recording, start, stop, supported } = useRecorder({
    meter: true,
    onSilence: () => { void finishTurn(); },
    silenceMs: AUTO_STOP_MS,
  });

  useEffect(() => {
    let alive = true;
    getPublicShop(id)
      .then((s) => { if (alive) setShop(s); })
      .catch(() => { if (alive) setLoadError(true); });
    return () => { alive = false; };
  }, [id]);

  useEffect(() => () => {
    playCtxRef.current?.close().catch(() => undefined);
    audioUrlsRef.current.forEach((u) => URL.revokeObjectURL(u));
  }, []);

  // Keep the newest message in view.
  useEffect(() => {
    threadRef.current?.scrollTo?.({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, busy]);

  const priceFor = (title?: string): number => {
    const c = (shop?.catalogs ?? []).find((x) => x.title.toLowerCase() === (title ?? '').toLowerCase());
    return c ? Number(c.price) || 0 : 0;
  };

  /* ---- audio playback (auto-play the reply; also gates the mic) ---------- */

  function primeAudio() {
    try {
      if (!playCtxRef.current) {
        const Ctx: typeof AudioContext = window.AudioContext
          || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
        playCtxRef.current = new Ctx();
      }
      void playCtxRef.current.resume();
    } catch { /* Web Audio unavailable */ }
  }

  function playBytes(bytes: ArrayBuffer): Promise<void> {
    return new Promise((resolve) => {
      const ctx = playCtxRef.current;
      if (!ctx) { resolve(); return; }
      ctx.decodeAudioData(bytes.slice(0))
        .then(async (buffer) => {
          try { playSrcRef.current?.stop(); } catch { /* nothing playing */ }
          const src = ctx.createBufferSource();
          src.buffer = buffer;
          src.connect(ctx.destination);
          src.onended = () => { setSpeaking(false); resolve(); };
          playSrcRef.current = src;
          setSpeaking(true);
          if (ctx.state === 'suspended') await ctx.resume();
          src.start(0);
        })
        .catch(() => { setSpeaking(false); resolve(); });
    });
  }

  // /tts round trip for client-composed lines (confirmation, error prompts).
  function speakReply(text: string): Promise<void> {
    if (!text || !playCtxRef.current) return Promise.resolve();
    return speak(text, 'nova')
      .then(async (url) => {
        const bytes = await (await fetch(url)).arrayBuffer();
        URL.revokeObjectURL(url);
        return playBytes(bytes);
      })
      .catch(() => { setSpeaking(false); });
  }

  // Play the assistant's own reply from inline audio; fall back to /tts.
  function speakServerReply(r: AssistantReply): Promise<void> {
    const b64 = r.reply_audio;
    if (b64 && playCtxRef.current) {
      try {
        const bin = atob(b64);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return playBytes(bytes.buffer);
      } catch { /* fall back */ }
    }
    return speakReply(r.reply_text);
  }

  function stopSpeaking() {
    try { playSrcRef.current?.stop(); } catch { /* nothing playing */ }
  }

  /* ---- bubbles ----------------------------------------------------------- */

  function pushUser(text: string) {
    if (text) setMessages((m) => [...m, { role: 'user', content: text }]);
  }
  function pushAssistant(text: string, audioB64?: string) {
    const audioUrl = audioB64 ? base64ToBlobUrl(audioB64) : null;
    if (audioUrl) audioUrlsRef.current.push(audioUrl);
    setMessages((m) => [...m, { role: 'assistant', content: text, audioUrl }]);
  }

  /* ---- booking ----------------------------------------------------------- */

  async function book(f: BookingFields): Promise<string | null> {
    if (!shop) return null;
    try {
      const b = await createBooking(shop.id, {
        services: [{ title: f.service!, price: priceFor(f.service) }],
        charges: priceFor(f.service),
        date: f.date!,
        start_time: f.start_time!,
        customer_name: f.customer_name!,
        customer_whatsapp: f.customer_phone!,
      });
      const bookingId = (b as { id?: number }).id;
      const reference = (b as { booking_reference?: string }).booking_reference || (bookingId ? `#${bookingId}` : '');
      bookedRef.current = true;
      setCreated({ service: f.service!, date: f.date!, start_time: f.start_time!, customer_name: f.customer_name!, reference });
      if (bookingId) { try { await recordBooking(shop.id, bookingId); } catch { /* best-effort */ } }
      return reference;
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      const t = msg && /closed/i.test(msg)
        ? "Sorry, we're closed then — please tell me another time."
        : "Sorry, I couldn't book that — please try again.";
      pushAssistant(t);
      await speakReply(t);
      return null;
    }
  }

  async function applyReply(userText: string, r: AssistantReply) {
    const merged = { ...fieldsRef.current, ...r.fields };
    fieldsRef.current = merged;
    if (userText) historyRef.current.push({ role: 'user', content: userText });
    if (r.reply_text) historyRef.current.push({ role: 'assistant', content: r.reply_text });
    historyRef.current = historyRef.current.slice(-12);

    const complete = !!(merged.service && merged.date && merged.start_time && merged.customer_name && merged.customer_phone);
    if (r.ready && complete) {
      const phone = canonicalUaeMobile(merged.customer_phone);
      if (!phone) {
        fieldsRef.current = { ...merged, customer_phone: undefined };
        const t = "That phone number doesn't look right. Please say your mobile number again slowly — it should start with zero-five and have ten digits, like oh five oh, one two three, four five six seven.";
        pushAssistant(t);
        await speakReply(t);
        return;
      }
      merged.customer_phone = phone;
      fieldsRef.current = merged;
      const reference = await book(merged);
      if (reference) {
        await speakReply(`Perfect, you're booked! Your reference is ${reference}. Please keep it for when you arrive.`);
      }
    } else {
      pushAssistant(r.reply_text, r.reply_audio);
      await speakServerReply(r);
    }
  }

  /* ---- turns ------------------------------------------------------------- */

  async function finishTurn() {
    if (finishingRef.current || !recording || !shop) return;
    finishingRef.current = true;
    const epoch = epochRef.current;
    setBusy(true);
    const blob = await stop();
    finishingRef.current = false;
    if (!blob) { if (epoch === epochRef.current) setBusy(false); return; }
    try {
      const r = await bookAssistantVoice(shop.id, blob, fieldsRef.current, historyRef.current);
      if (epoch !== epochRef.current) return;
      pushUser(r.transcript || '');
      await applyReply(r.transcript || '', r);
    } catch {
      if (epoch !== epochRef.current) return;
      await speakReply("Sorry, I didn't catch that — please try again.");
    } finally {
      if (epoch === epochRef.current) setBusy(false);
    }
  }

  async function sendText(text: string) {
    const t = text.trim();
    if (!t || busy || recording || !shop || bookedRef.current) return;
    const epoch = epochRef.current;
    setDraft('');
    primeAudio();
    pushUser(t);
    setBusy(true);
    try {
      const r = await bookAssistantText(shop.id, t, fieldsRef.current, historyRef.current);
      if (epoch !== epochRef.current) return;
      await applyReply(t, r);
    } catch {
      if (epoch !== epochRef.current) return;
      await speakReply("Sorry, I didn't catch that — please try again.");
    } finally {
      if (epoch === epochRef.current) setBusy(false);
    }
  }

  async function onMicTap() {
    if (!shop || bookedRef.current) return;
    if (busy && !recording) return;       // thinking / speaking — wait
    if (speaking) { stopSpeaking(); return; }
    if (recording) { void finishTurn(); return; }
    primeAudio();
    setMicDenied(false);
    try { await start(); } catch { setMicDenied(true); }
  }

  // "+" New booking (and "Book another"): fresh thread + fresh session.
  function newBooking() {
    epochRef.current += 1;   // invalidate any in-flight turn from the old thread
    stopSpeaking();
    if (recording) void stop();
    newBookingSession();
    audioUrlsRef.current.forEach((u) => URL.revokeObjectURL(u));
    audioUrlsRef.current = [];
    fieldsRef.current = { date: todayIso() };
    historyRef.current = [];
    bookedRef.current = false;
    setMessages([]);
    setCreated(null);
    setBusy(false);
    setMicDenied(false);
    setDraft('');
  }

  /* ---- render ------------------------------------------------------------ */

  if (loadError) {
    return <div className="pb-screen pb-solo"><div className="pb-empty"><Icons.Store size={28} /><p>This booking link isn't available right now.</p></div></div>;
  }

  if (created) {
    return (
      <div className="pb-screen pb-solo">
        <div className="pb-done">
          <div className="pb-done-tick"><Icons.Check size={30} /></div>
          <h2>You're booked!</h2>
          {created.reference && <div className="pb-done-ref">Reference <b>{created.reference}</b></div>}
          <p className="pb-done-sub">{created.service} · {created.date} at {created.start_time}</p>
          <p className="pb-done-sub">See you soon, {created.customer_name}{shop ? ` — ${shop.name}` : ''}.</p>
          <button className="c-btn c-btn-block" onClick={newBooking}>Book another</button>
        </div>
      </div>
    );
  }

  return (
    <div className="m-screen va-screen pb-chat">
      <div className="va-head">
        <div className="va-head-text">
          <span className="va-title">{shop?.name ?? 'Book'}</span>
          <span className="va-sub">Tell me what you'd like to book</span>
        </div>
        <button className="c-icon-btn" aria-label="New booking" onClick={newBooking}><Icons.Plus size={18} /></button>
      </div>

      <div className="va-thread" ref={threadRef}>
        {messages.length === 0 && !busy && (
          <div className="va-empty">
            <div className="va-empty-mic"><Icons.Mic size={26} /></div>
            <p className="va-hint">Tap the mic and tell me what you'd like to book{shop ? ` at ${shop.name}` : ''} — I'll listen and reply. You can type instead if you prefer.</p>
          </div>
        )}
        {messages.map((m, i) => (
          <div key={i} className={`va-bubble ${m.role === 'user' ? 'va-user' : 'va-ai'}`}>
            {m.audioUrl && <AudioBubble src={m.audioUrl} autoPlay={false} />}
            {m.content && <div className="va-text">{renderContent(m.content, { linkifyRefs: false })}</div>}
          </div>
        ))}
        {busy && <ThinkingBubble />}
        {micDenied && <div className="c-error-box">Allow the microphone in your browser, then tap the mic.</div>}
      </div>

      <div className="va-controls">
        <input className="va-input" placeholder="Type a message…" value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') void sendText(draft); }} disabled={busy || recording} />
        <button className="c-btn" aria-label="Send" disabled={busy || recording || !draft.trim()} onClick={() => void sendText(draft)}>
          <Icons.Send size={16} />
        </button>
        {supported && (
          <button className={`va-mic ${recording ? 'recording' : ''}`} aria-label="Microphone"
            disabled={busy && !recording} onClick={() => void onMicTap()}>
            <Icons.Mic size={20} />
          </button>
        )}
      </div>
    </div>
  );
}
