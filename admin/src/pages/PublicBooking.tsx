import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getPublicShop, bookAssistantText, bookAssistantVoice, type AssistantReply, type BookingFields, type PublicShop, type Turn } from '@/lib/publicBooking';
import { createBooking } from '@/lib/bookings';
import { useRecorder } from '@/hooks/useRecorder';
import { speak } from '@/lib/simulation';
import '@/styles/public-booking.css';

// TEMPORARY on-screen diagnostics — remove once the flow is confirmed on mobile.
const DEBUG = true;

type Created = { service: string; date: string; start_time: string; customer_name: string };

function todayIso(): string {
  const d = new Date();
  const y = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${mm}-${dd}`;
}

/* ---- Minimal Web Speech API typings (not uniformly in lib.dom) ------------ */
type SRResult = ArrayLike<{ transcript: string }> & { isFinal: boolean };
type SREvent = { results: ArrayLike<SRResult> };
type SR = {
  lang: string; continuous: boolean; interimResults: boolean;
  start: () => void; stop: () => void; abort: () => void;
  onresult: ((e: SREvent) => void) | null;
  onend: (() => void) | null;
  onerror: ((e: { error: string }) => void) | null;
};
function getSRClass(): (new () => SR) | null {
  const w = window as unknown as { SpeechRecognition?: new () => SR; webkitSpeechRecognition?: new () => SR };
  return w.SpeechRecognition || w.webkitSpeechRecognition || null;
}

/**
 * Voice-only self-service booking.
 *
 * Hands-free mode (Android Chrome & any browser with the Web Speech API): the
 * customer taps Start once to grant the mic, then it greets them and keeps
 * listening turn-after-turn — pausing only while it speaks — until the booking
 * is made. No further taps.
 *
 * Fallback mode (iOS/Safari etc. without speech recognition): a single mic that
 * records, auto-stops on a pause, transcribes via Whisper, and replies.
 */
export default function PublicBooking() {
  const { shopId } = useParams<{ shopId: string }>();
  const id = Number(shopId);

  const [shop, setShop] = useState<PublicShop | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [created, setCreated] = useState<Created | null>(null);
  const [busy, setBusy] = useState(false);
  const [speaking, setSpeaking] = useState(false);
  const [started, setStarted] = useState(false);   // hands-free: Start tapped
  const [micDenied, setMicDenied] = useState(false);

  const handsFree = getSRClass() !== null;

  // TEMPORARY diagnostics
  const [dbg, setDbg] = useState<{ status: string; heard: string; reply: string; err: string }>(
    { status: 'ready', heard: '', reply: '', err: '' });
  const log = (patch: Partial<{ status: string; heard: string; reply: string; err: string }>) =>
    setDbg((d) => ({ ...d, ...patch }));

  // Booking details + conversation so far (kept in refs; the UI shows neither).
  const fieldsRef = useRef<BookingFields>({ date: todayIso() });
  const historyRef = useRef<Turn[]>([]);
  // Playback AudioContext, unlocked inside the Start/mic tap so the spoken reply
  // can play after the network round-trip (mobile blocks audio otherwise).
  const playCtxRef = useRef<AudioContext | null>(null);
  const playSrcRef = useRef<AudioBufferSourceNode | null>(null);

  // Hands-free conversation state.
  const srRef = useRef<SR | null>(null);
  const wantListenRef = useRef(false);   // we intend to be listening (restart on onend)
  const processingRef = useRef(false);   // a turn is being handled — ignore new results
  const bookedRef = useRef(false);

  // Fallback (tap) recorder with voice-activity auto-stop.
  const { recording, start, stop, supported, level } = useRecorder({
    meter: true,
    onSilence: () => { void finishTurn(); },
  });
  const finishingRef = useRef(false);

  useEffect(() => {
    let alive = true;
    getPublicShop(id)
      .then((s) => { if (alive) setShop(s); })
      .catch(() => { if (alive) setLoadError(true); });
    return () => { alive = false; };
  }, [id]);

  // Tear down audio + recognition on leave.
  useEffect(() => () => {
    wantListenRef.current = false;
    try { srRef.current?.abort(); } catch { /* ignore */ }
    playCtxRef.current?.close().catch(() => undefined);
  }, []);

  const priceFor = (title?: string): number => {
    const c = (shop?.catalogs ?? []).find((x) => x.title.toLowerCase() === (title ?? '').toLowerCase());
    return c ? Number(c.price) || 0 : 0;
  };

  /* ---- audio playback ---------------------------------------------------- */

  // Run synchronously inside a user gesture so the context stays unlocked after.
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

  // Speak text; resolves when playback finishes (so the loop can resume listening).
  function speakReply(text: string): Promise<void> {
    return new Promise((resolve) => {
      const ctx = playCtxRef.current;
      if (!text || !ctx) { resolve(); return; }
      log({ status: 'speaking…' });
      speak(text, 'nova')
        .then(async (url) => {
          const bytes = await (await fetch(url)).arrayBuffer();
          URL.revokeObjectURL(url);
          const buffer = await ctx.decodeAudioData(bytes.slice(0));
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
        .catch((e: unknown) => { setSpeaking(false); log({ err: 'audio: ' + ((e as Error)?.message || String(e)) }); resolve(); });
    });
  }

  /* ---- booking ----------------------------------------------------------- */

  async function book(f: BookingFields): Promise<boolean> {
    if (!shop) return false;
    try {
      await createBooking(shop.id, {
        services: [{ title: f.service!, price: priceFor(f.service) }],
        charges: priceFor(f.service),
        date: f.date!,
        start_time: f.start_time!,
        customer_name: f.customer_name!,
        customer_whatsapp: f.customer_phone!,
      });
      bookedRef.current = true;
      setCreated({ service: f.service!, date: f.date!, start_time: f.start_time!, customer_name: f.customer_name! });
      return true;
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      await speakReply(msg && /closed/i.test(msg)
        ? "Sorry, we're closed then — please tell me another time."
        : "Sorry, I couldn't book that — please try again.");
      return false;
    }
  }

  // Merge the assistant's fields, remember the turn, speak the reply, and book
  // when everything is known. `userText` is what the customer just said.
  async function applyReply(userText: string, r: AssistantReply) {
    log({ heard: userText, reply: r.reply_text || '(no reply)', err: '' });
    const merged = { ...fieldsRef.current, ...r.fields };
    fieldsRef.current = merged;
    if (userText) historyRef.current.push({ role: 'user', content: userText });
    if (r.reply_text) historyRef.current.push({ role: 'assistant', content: r.reply_text });
    historyRef.current = historyRef.current.slice(-12);

    const complete = !!(merged.service && merged.date && merged.start_time && merged.customer_name && merged.customer_phone);
    if (r.ready && complete) {
      await speakReply(r.reply_text || 'Perfect, booking that now.');
      await book(merged);
    } else {
      await speakReply(r.reply_text);
    }
  }

  /* ---- hands-free (continuous) mode ------------------------------------- */

  function startListening() {
    const SRClass = getSRClass();
    if (!SRClass) return;
    if (!srRef.current) {
      const sr = new SRClass();
      sr.lang = 'en-US';
      sr.continuous = true;
      sr.interimResults = false;
      sr.onresult = (e: SREvent) => {
        const last = e.results[e.results.length - 1];
        if (!last || !last.isFinal) return;
        const text = (last[0]?.transcript || '').trim();
        if (text) void onUtterance(text);
      };
      sr.onerror = (e: { error: string }) => {
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { setMicDenied(true); wantListenRef.current = false; }
        else log({ err: 'speech: ' + e.error });
      };
      // Recognition ends itself periodically — restart while we still want to listen.
      sr.onend = () => { if (wantListenRef.current) { try { srRef.current?.start(); } catch { /* already running */ } } };
      srRef.current = sr;
    }
    wantListenRef.current = true;
    try { srRef.current.start(); log({ status: 'listening…' }); } catch { /* already started */ }
  }

  function stopListening() {
    wantListenRef.current = false;
    try { srRef.current?.stop(); } catch { /* ignore */ }
  }

  async function onUtterance(text: string) {
    if (processingRef.current || speaking || bookedRef.current || !shop) return;
    if (/\b(cancel|never mind|forget it|stop booking)\b/i.test(text)) {
      stopListening();
      await speakReply('No problem — cancelled. Tap start whenever you want to book.');
      setStarted(false);
      return;
    }
    processingRef.current = true;
    stopListening();               // pause so we don't transcribe our own reply
    setBusy(true);
    log({ status: 'thinking…' });
    try {
      const r = await bookAssistantText(shop.id, text, fieldsRef.current, historyRef.current);
      await applyReply(text, r);
    } catch (e: unknown) {
      log({ err: 'request: ' + ((e as Error)?.message || String(e)) });
      await speakReply('Sorry, I didn\'t catch that — please say it again.');
    } finally {
      setBusy(false);
      processingRef.current = false;
      if (!bookedRef.current) startListening();   // back to listening for the next turn
    }
  }

  async function onStart() {
    if (!shop) return;
    primeAudio();
    setStarted(true);
    setMicDenied(false);
    await speakReply(`Hi! Welcome to ${shop.name}. What would you like to book?`);
    startListening();
  }

  /* ---- fallback (tap) mode ---------------------------------------------- */

  async function finishTurn() {
    if (finishingRef.current || !recording || !shop) return;
    finishingRef.current = true;
    setBusy(true);
    log({ status: 'stopping…' });
    const blob = await stop();
    finishingRef.current = false;
    if (!blob) { setBusy(false); log({ err: 'no audio recorded' }); return; }
    try {
      log({ status: `sending ${blob.size}B…` });
      const r = await bookAssistantVoice(shop.id, blob, fieldsRef.current, historyRef.current);
      await applyReply(r.transcript || '', r);
    } catch (e: unknown) {
      log({ err: 'request: ' + ((e as Error)?.message || String(e)) });
      await speakReply("Sorry, I didn't catch that — please try again.");
    } finally {
      setBusy(false);
    }
  }

  async function onMicTap() {
    if (!shop || busy) return;
    primeAudio();
    if (recording) { void finishTurn(); }
    else { log({ status: 'listening…', err: '' }); try { await start(); } catch { log({ err: 'mic permission denied' }); } }
  }

  /* ---- render ------------------------------------------------------------ */

  const micState = (recording || (started && wantListenRef.current && !busy && !speaking)) ? 'listening'
    : busy ? 'thinking' : speaking ? 'speaking' : 'idle';

  function reset() {
    fieldsRef.current = { date: todayIso() };
    historyRef.current = [];
    bookedRef.current = false;
    setCreated(null);
    setStarted(false);
  }

  if (loadError) {
    return <div className="pb-screen pb-solo"><div className="pb-empty"><Icons.Store size={28} /><p>This booking link isn't available right now.</p></div></div>;
  }

  if (created) {
    return (
      <div className="pb-screen pb-solo">
        <div className="pb-done">
          <div className="pb-done-tick"><Icons.Check size={30} /></div>
          <h2>You're booked!</h2>
          <p className="pb-done-sub">{created.service} · {created.date} at {created.start_time}</p>
          <p className="pb-done-sub">See you soon, {created.customer_name}{shop ? ` — ${shop.name}` : ''}.</p>
          <button className="c-btn c-btn-block" onClick={reset}>Book another</button>
        </div>
      </div>
    );
  }

  // Hands-free: a Start button first (grants mic), then a hands-free listening orb.
  const showStart = handsFree && !started;

  return (
    <div className="pb-screen pb-solo">
      <button
        className={`pb-mic pb-mic-${showStart ? 'idle' : micState}`}
        style={{ ['--lvl' as string]: recording ? level.toFixed(3) : 0 }}
        aria-label={showStart ? 'Start' : handsFree ? 'Listening' : (recording ? 'Stop' : 'Speak to book')}
        disabled={!shop || (!handsFree && !supported) || (busy && !recording && !started)}
        onClick={() => { if (showStart) void onStart(); else if (!handsFree) void onMicTap(); }}
      >
        <span className="pb-mic-ring" aria-hidden />
        <span className="pb-mic-ring pb-mic-ring2" aria-hidden />
        <Icons.Mic size={44} />
      </button>

      {showStart && <p className="pb-hint">Tap to start — then just talk</p>}
      {micDenied && <p className="pb-hint">Microphone blocked. Allow it in your browser, then tap to start again.</p>}

      {DEBUG && (
        <div style={{ marginTop: 24, width: '100%', maxWidth: 340, fontSize: 13, lineHeight: 1.5,
          fontFamily: 'monospace', color: 'var(--text-2)', textAlign: 'left',
          background: 'var(--bg-2)', border: '1px solid var(--border-1)', borderRadius: 12, padding: 12 }}>
          <div><b>mode:</b> {handsFree ? 'hands-free' : 'tap'}</div>
          <div><b>status:</b> {dbg.status}</div>
          <div><b>heard:</b> {dbg.heard || '—'}</div>
          <div><b>reply:</b> {dbg.reply || '—'}</div>
          <div style={{ color: dbg.err ? 'var(--danger)' : 'inherit' }}><b>error:</b> {dbg.err || 'none'}</div>
        </div>
      )}
    </div>
  );
}
