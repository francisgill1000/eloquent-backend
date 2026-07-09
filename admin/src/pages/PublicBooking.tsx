import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { VoiceOrb } from '@/components/VoiceOrb';
import { getPublicShop, bookAssistantText, bookAssistantVoice, recordBooking, type AssistantReply, type BookingFields, type PublicShop, type Turn } from '@/lib/publicBooking';
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

// Hands-free turn-taking: after the caller stops making sound, wait this long
// before treating the turn as finished — long enough to ride out a mid-sentence
// breath, short enough not to feel laggy.
const END_SILENCE_MS = 900;
// After we finish speaking, wait this long before re-opening the mic so the tail
// of our own audio isn't heard as the caller starting to talk.
const RESUME_GAP_MS = 300;

/* ---- Minimal Web Speech API typings (not uniformly in lib.dom) ------------ */
type SRResult = ArrayLike<{ transcript: string }> & { isFinal: boolean };
type SREvent = { results: ArrayLike<SRResult>; resultIndex: number };
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
  // Turn-taking buffers. speakingRef mirrors the `speaking` state for the
  // recognition callbacks (which capture stale state otherwise); the transcript
  // accumulates across pauses until END_SILENCE_MS of quiet ends the turn.
  const speakingRef = useRef(false);
  const bufferRef = useRef('');          // finalised speech so far this turn
  const interimRef = useRef('');         // latest not-yet-final words (fallback)
  const finalizeTimerRef = useRef<number | null>(null);
  const onUtteranceRef = useRef<(t: string) => void>(() => {});

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
    if (finalizeTimerRef.current) window.clearTimeout(finalizeTimerRef.current);
    playCtxRef.current?.close().catch(() => undefined);
  }, []);

  // Keep the recognition callbacks pointing at the latest onUtterance so a turn
  // never fires with stale shop / fields / state.
  useEffect(() => { onUtteranceRef.current = onUtterance; });

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
      speak(text, 'nova')
        .then(async (url) => {
          const bytes = await (await fetch(url)).arrayBuffer();
          URL.revokeObjectURL(url);
          const buffer = await ctx.decodeAudioData(bytes.slice(0));
          try { playSrcRef.current?.stop(); } catch { /* nothing playing */ }
          const src = ctx.createBufferSource();
          src.buffer = buffer;
          src.connect(ctx.destination);
          src.onended = () => { speakingRef.current = false; setSpeaking(false); resolve(); };
          playSrcRef.current = src;
          speakingRef.current = true;
          setSpeaking(true);
          if (ctx.state === 'suspended') await ctx.resume();
          src.start(0);
        })
        .catch(() => { speakingRef.current = false; setSpeaking(false); resolve(); });
    });
  }

  // Interrupt: stop the current spoken reply. Stopping the source fires its
  // onended, which resolves the pending speakReply and lets the flow resume
  // (hands-free goes straight back to listening).
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
      await speakReply(r.reply_text);
    }
  }

  /* ---- hands-free (continuous) mode ------------------------------------- */

  // End the turn once the caller has been quiet for END_SILENCE_MS. Uses refs
  // only, so it's safe to call from the (once-created) recognition callback.
  function scheduleFinalize() {
    if (finalizeTimerRef.current) window.clearTimeout(finalizeTimerRef.current);
    finalizeTimerRef.current = window.setTimeout(() => {
      finalizeTimerRef.current = null;
      const text = (bufferRef.current || interimRef.current).trim();
      bufferRef.current = '';
      interimRef.current = '';
      if (text) void onUtteranceRef.current(text);
    }, END_SILENCE_MS);
  }

  function startListening() {
    const SRClass = getSRClass();
    if (!SRClass) return;
    if (!srRef.current) {
      const sr = new SRClass();
      sr.lang = 'en-US';
      sr.continuous = true;
      sr.interimResults = true;   // hear the caller mid-sentence, not only when they stop
      sr.onresult = (e: SREvent) => {
        // Ignore anything picked up while we're thinking or speaking (e.g. the
        // tail of our own reply) so it can't start or corrupt a turn.
        if (processingRef.current || speakingRef.current) return;
        let heard = false, interim = '';
        for (let i = e.resultIndex ?? 0; i < e.results.length; i++) {
          const res = e.results[i];
          const t = res[0]?.transcript || '';
          if (!t.trim()) continue;
          heard = true;
          if (res.isFinal) bufferRef.current = (bufferRef.current + ' ' + t).trim();
          else interim += t;
        }
        interimRef.current = interim.trim();
        // Any sound — even interim — means they're still talking: hold off and
        // reset the clock. We only act after END_SILENCE_MS of true quiet.
        if (heard) scheduleFinalize();
      };
      sr.onerror = (e: { error: string }) => {
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { setMicDenied(true); wantListenRef.current = false; }
      };
      // Recognition ends itself periodically — restart while we still want to listen.
      sr.onend = () => { if (wantListenRef.current) { try { srRef.current?.start(); } catch { /* already running */ } } };
      srRef.current = sr;
    }
    wantListenRef.current = true;
    try { srRef.current.start(); } catch { /* already started */ }
  }

  function stopListening() {
    wantListenRef.current = false;
    if (finalizeTimerRef.current) { window.clearTimeout(finalizeTimerRef.current); finalizeTimerRef.current = null; }
    bufferRef.current = '';
    interimRef.current = '';
    try { srRef.current?.abort(); } catch { /* ignore */ }
  }

  async function onUtterance(text: string) {
    if (processingRef.current || speakingRef.current || bookedRef.current || !shop) return;
    if (/\b(cancel|never mind|forget it|stop booking)\b/i.test(text)) {
      stopListening();
      await speakReply('No problem — cancelled. Tap start whenever you want to book.');
      setStarted(false);
      return;
    }
    processingRef.current = true;
    stopListening();               // pause so we don't transcribe our own reply
    setBusy(true);
    try {
      const r = await bookAssistantText(shop.id, text, fieldsRef.current, historyRef.current);
      await applyReply(text, r);
    } catch {
      await speakReply('Sorry, I didn\'t catch that — please say it again.');
    } finally {
      setBusy(false);
      processingRef.current = false;
      // Re-open the mic after a short gap so the tail of our own audio isn't
      // heard as the caller talking (which used to cut the reply / mis-fire).
      if (!bookedRef.current) window.setTimeout(() => {
        if (!processingRef.current && !speakingRef.current && !bookedRef.current) startListening();
      }, RESUME_GAP_MS);
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

  // End the whole session and return to the Start screen. Tears down listening,
  // any in-flight speech, and the tap recorder.
  async function endSession() {
    stopListening();
    stopSpeaking();
    processingRef.current = false;
    try { if (recording) await stop(); } catch { /* already stopped */ }
    reset();
  }

  /* ---- fallback (tap) mode ---------------------------------------------- */

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

  async function onMicTap() {
    if (!shop || busy) return;
    primeAudio();
    if (recording) { void finishTurn(); }
    else { try { await start(); } catch { /* mic permission denied */ } }
  }

  // One tap handler for the orb: start when idle, interrupt when speaking,
  // record in tap mode. In hands-free listening/thinking it's a no-op.
  function onOrbTap() {
    if (showStart) { void onStart(); return; }
    if (speaking) { stopSpeaking(); return; }
    if (!handsFree) { void onMicTap(); }
  }

  /* ---- render ------------------------------------------------------------ */

  // Speaking wins over busy: a reply turn keeps `busy` true while the assistant
  // is talking, so without this the voice bars would only ever show on the first
  // greeting (the one reply that runs outside a busy turn).
  const micState = speaking ? 'speaking'
    : (recording || (started && wantListenRef.current && !busy)) ? 'listening'
    : busy ? 'thinking'
    : 'idle';

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
          {created.reference && <div className="pb-done-ref">Reference <b>{created.reference}</b></div>}
          <p className="pb-done-sub">{created.service} · {created.date} at {created.start_time}</p>
          <p className="pb-done-sub">See you soon, {created.customer_name}{shop ? ` — ${shop.name}` : ''}.</p>
          <button className="c-btn c-btn-block" onClick={reset}>Book another</button>
        </div>
      </div>
    );
  }

  // Hands-free: a Start button first (grants mic), then a hands-free listening orb.
  const showStart = handsFree && !started;
  const orbState = showStart ? 'idle' : micState;
  const liveSession = started || recording || speaking || busy;

  const subhint = micDenied ? 'Allow the microphone in your browser, then tap to start.'
    : showStart ? 'Tap once, then just talk — I’ll listen and reply.'
    : micState === 'speaking' ? 'Tap the circle to interrupt.'
    : micState === 'listening' ? 'Just speak — I’m listening.'
    : (!handsFree && micState === 'idle') ? 'Tap the mic and speak.'
    : '';

  const orbLabel = showStart ? 'Start'
    : speaking ? 'Tap to interrupt'
    : handsFree ? 'Listening'
    : (recording ? 'Stop' : 'Speak to book');

  return (
    <div className="pb-screen pb-live">
      {liveSession && (
        <button className="pb-end" aria-label="End" onClick={() => void endSession()}>End</button>
      )}

      <div className="pb-stage">
        <VoiceOrb
          state={orbState}
          level={recording ? level : 0}
          ariaLabel={orbLabel}
          disabled={!shop || (!handsFree && !supported) || (busy && !recording && !started)}
          onTap={onOrbTap}
        />
      </div>

      <div className="pb-foot">
        {subhint && <p className="pb-hint">{subhint}</p>}
      </div>
    </div>
  );
}
