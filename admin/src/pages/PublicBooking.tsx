import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { VoiceOrb } from '@/components/VoiceOrb';
import { getPublicShop, bookAssistantVoice, recordBooking, type AssistantReply, type BookingFields, type PublicShop, type Turn } from '@/lib/publicBooking';
import { createBooking } from '@/lib/bookings';
import { useRecorder } from '@/hooks/useRecorder';
import { speak } from '@/lib/simulation';
import '@/styles/public-booking.css';

type Created = { service: string; date: string; start_time: string; customer_name: string; reference: string };

function todayIso(): string {
  const d = new Date();
  const y = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${mm}-${dd}`;
}

// Normalise a spoken/typed number to a canonical UAE mobile (05XXXXXXXX), or
// return null if it isn't a valid UAE mobile. Voice transcription of numbers is
// noisy, so we're lenient about the input shape but strict about the result.
function canonicalUaeMobile(raw?: string): string | null {
  let d = (raw || '').replace(/\D+/g, '');
  if (d.startsWith('971')) d = d.slice(3);          // strip country code
  if (d.length === 9 && d.startsWith('5')) d = '0' + d; // 5XXXXXXXX -> 05XXXXXXXX
  return /^05\d{8}$/.test(d) ? d : null;
}

/**
 * Voice self-service booking — tap-to-talk.
 *
 * Deliberately the same turn-taking model as the owner's Ask assistant
 * (VoiceAssistant.tsx): the customer taps the mic, speaks for as long as they
 * like, and taps again to send. The app NEVER tries to guess when a sentence
 * has ended, so it can't cut the customer off mid-thought. Each tap records a
 * note, sends it to Whisper, and the assistant's reply is spoken back; the
 * customer taps once more to answer. Works identically on Android and iOS.
 */
export default function PublicBooking() {
  const { shopId } = useParams<{ shopId: string }>();
  const id = Number(shopId);

  const [shop, setShop] = useState<PublicShop | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [created, setCreated] = useState<Created | null>(null);
  const [busy, setBusy] = useState(false);       // sending / assistant thinking
  const [speaking, setSpeaking] = useState(false); // playing the spoken reply
  const [started, setStarted] = useState(false);   // first mic tap happened
  const [micDenied, setMicDenied] = useState(false);

  // Booking details + conversation so far (kept in refs; the UI shows neither).
  const fieldsRef = useRef<BookingFields>({ date: todayIso() });
  const historyRef = useRef<Turn[]>([]);
  const bookedRef = useRef(false);
  // Playback AudioContext, unlocked inside the first mic tap so the spoken reply
  // can play after the network round-trip (mobile blocks audio otherwise).
  const playCtxRef = useRef<AudioContext | null>(null);
  const playSrcRef = useRef<AudioBufferSourceNode | null>(null);

  // Tap to record; the customer taps again to send. Safety net: if they go
  // quiet for AUTO_STOP_MS after speaking (and forget to tap), we send for them.
  // The window is long and measured on the real audio level (not the flaky Web
  // Speech API), so a normal mid-sentence pause won't trip it — and a tap still
  // sends immediately.
  const AUTO_STOP_MS = 1500;
  const { recording, start, stop, supported, level } = useRecorder({
    meter: true,
    onSilence: () => { void finishTurn(); },
    silenceMs: AUTO_STOP_MS,
  });
  const finishingRef = useRef(false);

  useEffect(() => {
    let alive = true;
    getPublicShop(id)
      .then((s) => { if (alive) setShop(s); })
      .catch(() => { if (alive) setLoadError(true); });
    return () => { alive = false; };
  }, [id]);

  // Tear down audio on leave.
  useEffect(() => () => {
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

  // Decode + play raw audio bytes on the primed context; resolves when done.
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

  // Speak text via a /tts round trip. Used for lines we compose on the client
  // (the booking confirmation, phone-error and closed prompts) that the server
  // didn't pre-voice.
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

  // Speak the assistant's own reply. The server voices it and returns the audio
  // inline (reply_audio), so play that directly — no extra round trip. Fall back
  // to a /tts synth only when the inline audio is missing.
  function speakServerReply(r: AssistantReply): Promise<void> {
    const b64 = r.reply_audio;
    if (b64 && playCtxRef.current) {
      try {
        const bin = atob(b64);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return playBytes(bytes.buffer);
      } catch { /* corrupt payload — fall back to synth */ }
    }
    return speakReply(r.reply_text);
  }

  // Interrupt the current spoken reply. Stopping the source fires its onended,
  // which resolves the pending speakReply.
  function stopSpeaking() {
    try { playSrcRef.current?.stop(); } catch { /* nothing playing */ }
  }

  /* ---- booking ----------------------------------------------------------- */

  // Create the booking; returns its reference (BK…) so we can tell the customer
  // and record it in the conversation, or null on failure.
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
      // Record the reference into the saved conversation so staff can refer to it.
      if (bookingId) { try { await recordBooking(shop.id, bookingId); } catch { /* best-effort */ } }
      return reference;
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      await speakReply(msg && /closed/i.test(msg)
        ? "Sorry, we're closed then — please tell me another time."
        : "Sorry, I couldn't book that — please try again.");
      return null;
    }
  }

  // Merge the assistant's fields, remember the turn, speak the reply, and book
  // when everything is known. `userText` is what the customer just said.
  async function applyReply(userText: string, r: AssistantReply) {
    const merged = { ...fieldsRef.current, ...r.fields };
    fieldsRef.current = merged;
    if (userText) historyRef.current.push({ role: 'user', content: userText });
    if (r.reply_text) historyRef.current.push({ role: 'assistant', content: r.reply_text });
    historyRef.current = historyRef.current.slice(-12);

    const complete = !!(merged.service && merged.date && merged.start_time && merged.customer_name && merged.customer_phone);
    if (r.ready && complete) {
      // Guard the phone before booking so a mis-heard number gets a clear,
      // spoken correction instead of a generic "couldn't book".
      const phone = canonicalUaeMobile(merged.customer_phone);
      if (!phone) {
        fieldsRef.current = { ...merged, customer_phone: undefined };
        await speakReply("That phone number doesn't look right. Please say your mobile number again slowly — it should start with zero-five and have ten digits, like oh five oh, one two three, four five six seven.");
        return;
      }
      merged.customer_phone = phone;
      fieldsRef.current = merged;
      const reference = await book(merged);
      if (reference) {
        await speakReply(`Perfect, you're booked! Your reference is ${reference}. Please keep it for when you arrive.`);
      }
    } else {
      await speakServerReply(r);
    }
  }

  /* ---- tap-to-talk ------------------------------------------------------- */

  // Stop recording, transcribe the note, and handle the assistant's reply.
  async function finishTurn() {
    if (finishingRef.current || !recording || !shop) return;
    finishingRef.current = true;
    setBusy(true);
    const blob = await stop();
    finishingRef.current = false;
    if (!blob) { setBusy(false); return; }
    try {
      const r = await bookAssistantVoice(shop.id, blob, fieldsRef.current, historyRef.current);
      await applyReply(r.transcript || '', r);
    } catch {
      await speakReply("Sorry, I didn't catch that — please try again.");
    } finally {
      setBusy(false);
    }
  }

  // The one mic handler: send when recording, otherwise start a new note. It's a
  // no-op while thinking; a tap during the spoken reply interrupts it.
  async function onMicTap() {
    if (!shop || bookedRef.current) return;
    if (busy && !recording) return;      // thinking / speaking — wait
    if (speaking) { stopSpeaking(); return; }
    if (recording) { void finishTurn(); return; }
    primeAudio();                        // unlock playback inside this gesture
    setStarted(true);
    setMicDenied(false);
    try { await start(); } catch { setMicDenied(true); }
  }

  // End the whole session and return to the start state.
  async function endSession() {
    stopSpeaking();
    try { if (recording) await stop(); } catch { /* already stopped */ }
    reset();
  }

  /* ---- render ------------------------------------------------------------ */

  const micState = speaking ? 'speaking'
    : recording ? 'listening'
    : busy ? 'thinking'
    : 'idle';

  function reset() {
    fieldsRef.current = { date: todayIso() };
    historyRef.current = [];
    bookedRef.current = false;
    setCreated(null);
    setStarted(false);
    setBusy(false);
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
          {created.reference && <div className="pb-done-ref">Reference <b>{created.reference}</b></div>}
          <p className="pb-done-sub">{created.service} · {created.date} at {created.start_time}</p>
          <p className="pb-done-sub">See you soon, {created.customer_name}{shop ? ` — ${shop.name}` : ''}.</p>
          <button className="c-btn c-btn-block" onClick={reset}>Book another</button>
        </div>
      </div>
    );
  }

  const liveSession = started || recording || speaking || busy;

  const subhint = micDenied ? 'Allow the microphone in your browser, then tap the mic.'
    : micState === 'speaking' ? 'Tap the circle to interrupt.'
    : micState === 'listening' ? "Speak — I'll send when you pause, or tap."
    : micState === 'thinking' ? ''
    : !started ? `Tap the mic and tell me what you'd like to book${shop ? ` at ${shop.name}` : ''}.`
    : 'Tap the mic to reply.';

  const orbLabel = speaking ? 'Tap to interrupt'
    : recording ? 'Stop'
    : busy ? 'Thinking'
    : 'Speak to book';

  return (
    <div className="pb-screen pb-live">
      {liveSession && (
        <button className="pb-end" aria-label="End" onClick={() => void endSession()}>End</button>
      )}

      <div className="pb-stage">
        <VoiceOrb
          state={micState}
          level={recording ? level : 0}
          ariaLabel={orbLabel}
          disabled={!shop || !supported || (busy && !recording)}
          onTap={() => void onMicTap()}
        />
      </div>

      <div className="pb-foot">
        {subhint && <p className="pb-hint">{subhint}</p>}
      </div>
    </div>
  );
}
