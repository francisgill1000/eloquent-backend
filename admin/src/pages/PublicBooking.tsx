import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getPublicShop, bookAssistantVoice, type AssistantReply, type BookingFields, type PublicShop, type Turn } from '@/lib/publicBooking';
import { createBooking } from '@/lib/bookings';
import { useRecorder } from '@/hooks/useRecorder';
import { speak } from '@/lib/simulation';
import '@/styles/public-booking.css';

// TEMPORARY on-screen diagnostics — remove once mobile audio is confirmed.
const DEBUG = true;

type Created = { service: string; date: string; start_time: string; customer_name: string };

function todayIso(): string {
  const d = new Date();
  const y = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${mm}-${dd}`;
}


/**
 * Voice-only self-service booking. The whole screen is one big mic: the
 * customer taps and speaks, the assistant replies out loud and collects the
 * details, and the moment it has everything the booking is created. No form,
 * no buttons — the conversation is the interface.
 */
export default function PublicBooking() {
  const { shopId } = useParams<{ shopId: string }>();
  const id = Number(shopId);

  const [shop, setShop] = useState<PublicShop | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [created, setCreated] = useState<Created | null>(null);
  const [busy, setBusy] = useState(false);
  const [speaking, setSpeaking] = useState(false);
  // TEMPORARY diagnostics
  const [dbg, setDbg] = useState<{ status: string; heard: string; reply: string; err: string }>(
    { status: 'ready', heard: '', reply: '', err: '' });
  const log = (patch: Partial<{ status: string; heard: string; reply: string; err: string }>) =>
    setDbg((d) => ({ ...d, ...patch }));

  const { recording, start, stop, supported, level } = useRecorder({ meter: true });
  // The booking details gathered so far live in a ref (not state) so each voice
  // turn merges onto the latest values without stale-closure surprises — the UI
  // itself shows none of them. The conversation so far rides alongside so the
  // assistant remembers what it already asked.
  const fieldsRef = useRef<BookingFields>({ date: todayIso() });
  const historyRef = useRef<Turn[]>([]);
  // A playback AudioContext resumed inside the mic tap. Once a gesture unlocks
  // it, it can play the spoken reply at any later time — an HTMLAudioElement
  // play() started after the network round-trip is blocked on iOS/Safari and
  // would only fire on the *next* tap.
  const playCtxRef = useRef<AudioContext | null>(null);
  const playSrcRef = useRef<AudioBufferSourceNode | null>(null);

  useEffect(() => {
    let alive = true;
    getPublicShop(id)
      .then((s) => { if (alive) setShop(s); })
      .catch(() => { if (alive) setLoadError(true); });
    return () => { alive = false; };
  }, [id]);

  // Close the audio context when leaving the page.
  useEffect(() => () => { playCtxRef.current?.close().catch(() => undefined); }, []);

  const priceFor = (title?: string): number => {
    const c = (shop?.catalogs ?? []).find((x) => x.title.toLowerCase() === (title ?? '').toLowerCase());
    return c ? Number(c.price) || 0 : 0;
  };

  // Must run synchronously inside a user gesture (the mic tap) so the context
  // is created/resumed while a gesture is active, keeping it unlocked afterwards.
  function primeAudio() {
    try {
      if (!playCtxRef.current) {
        const Ctx: typeof AudioContext = window.AudioContext
          || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
        playCtxRef.current = new Ctx();
      }
      void playCtxRef.current.resume();
    } catch { /* Web Audio unavailable — reply just won't be spoken */ }
  }

  // Speak the assistant's reply by decoding the TTS clip and playing it through
  // the already-unlocked context, so it sounds immediately (not on the next tap).
  async function speakReply(text: string) {
    const ctx = playCtxRef.current;
    if (!text) return;
    if (!ctx) { log({ err: 'no audio context (tap the mic first)' }); return; }
    try {
      log({ status: `ctx=${ctx.state}; fetching voice…` });
      const url = await speak(text, 'nova');
      const bytes = await (await fetch(url)).arrayBuffer();
      URL.revokeObjectURL(url);
      log({ status: `decoding ${bytes.byteLength}B…` });
      const buffer = await ctx.decodeAudioData(bytes.slice(0));
      try { playSrcRef.current?.stop(); } catch { /* nothing playing */ }
      const src = ctx.createBufferSource();
      src.buffer = buffer;
      src.connect(ctx.destination);
      src.onended = () => { setSpeaking(false); log({ status: 'done' }); };
      playSrcRef.current = src;
      setSpeaking(true);
      if (ctx.state === 'suspended') await ctx.resume();
      src.start(0);
      log({ status: `playing (ctx=${ctx.state})` });
    } catch (e: unknown) {
      setSpeaking(false);
      log({ err: 'audio: ' + ((e as Error)?.message || String(e)) });
    }
  }

  async function book(f: BookingFields) {
    if (!shop) return;
    setBusy(true);
    try {
      await createBooking(shop.id, {
        services: [{ title: f.service!, price: priceFor(f.service) }],
        charges: priceFor(f.service),
        date: f.date!,
        start_time: f.start_time!,
        customer_name: f.customer_name!,
        customer_whatsapp: f.customer_phone!,
      });
      setCreated({ service: f.service!, date: f.date!, start_time: f.start_time!, customer_name: f.customer_name! });
    } catch (e: unknown) {
      // No on-screen feedback in voice mode — say the problem so the caller can retry.
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      speakReply(msg && /closed/i.test(msg)
        ? "Sorry, we're closed then — please tell me another time."
        : "Sorry, I couldn't book that — please try again.");
    } finally {
      setBusy(false);
    }
  }

  async function handleReply(r: AssistantReply) {
    log({ heard: r.transcript || '(nothing)', reply: r.reply_text || '(no reply)', err: '' });
    const merged = { ...fieldsRef.current, ...r.fields };
    fieldsRef.current = merged;
    // Record this turn so the next request carries the conversation forward.
    if (r.transcript) historyRef.current.push({ role: 'user', content: r.transcript });
    if (r.reply_text) historyRef.current.push({ role: 'assistant', content: r.reply_text });
    historyRef.current = historyRef.current.slice(-12);
    speakReply(r.reply_text);
    const complete = !!(merged.service && merged.date && merged.start_time && merged.customer_name && merged.customer_phone);
    if (r.ready && complete) await book(merged);
  }

  async function toggleMic() {
    if (!shop || busy) return;
    primeAudio(); // unlock audio playback within this tap gesture
    if (recording) {
      setBusy(true);
      log({ status: 'stopping…' });
      const blob = await stop();
      if (!blob) { setBusy(false); log({ err: 'no audio recorded' }); return; }
      try {
        log({ status: `sending ${blob.size}B…` });
        await handleReply(await bookAssistantVoice(shop.id, blob, fieldsRef.current, historyRef.current));
      } catch (e: unknown) {
        log({ err: 'request: ' + ((e as Error)?.message || String(e)) });
        speakReply("Sorry, I didn't catch that — please try again.");
      } finally {
        setBusy(false);
      }
    } else {
      log({ status: 'listening…', err: '' });
      try { await start(); } catch { log({ err: 'mic permission denied' }); }
    }
  }

  const micState = recording ? 'listening' : busy ? 'thinking' : speaking ? 'speaking' : 'idle';

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
          <button className="c-btn c-btn-block" onClick={() => { fieldsRef.current = { date: todayIso() }; historyRef.current = []; setCreated(null); }}>
            Book another
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="pb-screen pb-solo">
      <button
        className={`pb-mic pb-mic-${micState}`}
        style={{ ['--lvl' as string]: recording ? level.toFixed(3) : 0 }}
        aria-label={recording ? 'Stop' : 'Speak to book'}
        disabled={!supported || !shop || (busy && !recording)}
        onClick={() => void toggleMic()}
      >
        <span className="pb-mic-ring" aria-hidden />
        <span className="pb-mic-ring pb-mic-ring2" aria-hidden />
        <Icons.Mic size={44} />
      </button>

      {DEBUG && (
        <div style={{ marginTop: 24, width: '100%', maxWidth: 340, fontSize: 13, lineHeight: 1.5,
          fontFamily: 'monospace', color: 'var(--text-2)', textAlign: 'left',
          background: 'var(--bg-2)', border: '1px solid var(--border-1)', borderRadius: 12, padding: 12 }}>
          <div><b>status:</b> {dbg.status}</div>
          <div><b>heard:</b> {dbg.heard || '—'}</div>
          <div><b>reply:</b> {dbg.reply || '—'}</div>
          <div style={{ color: dbg.err ? 'var(--danger)' : 'inherit' }}><b>error:</b> {dbg.err || 'none'}</div>
          <button className="c-btn" style={{ marginTop: 10, width: '100%' }}
            onClick={() => { primeAudio(); void speakReply('Testing, one, two, three.'); }}>
            🔊 Test sound
          </button>
        </div>
      )}
    </div>
  );
}
