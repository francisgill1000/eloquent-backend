import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getPublicShop, bookAssistantVoice, type AssistantReply, type BookingFields, type PublicShop } from '@/lib/publicBooking';
import { createBooking } from '@/lib/bookings';
import { useRecorder } from '@/hooks/useRecorder';
import { speak } from '@/lib/simulation';
import '@/styles/public-booking.css';

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

  const { recording, start, stop, supported, level } = useRecorder({ meter: true });
  // The booking details gathered so far live in a ref (not state) so each voice
  // turn merges onto the latest values without stale-closure surprises — the UI
  // itself shows none of them.
  const fieldsRef = useRef<BookingFields>({ date: todayIso() });
  const replyAudioRef = useRef<HTMLAudioElement | null>(null);

  useEffect(() => {
    let alive = true;
    getPublicShop(id)
      .then((s) => { if (alive) setShop(s); })
      .catch(() => { if (alive) setLoadError(true); });
    return () => { alive = false; };
  }, [id]);

  const priceFor = (title?: string): number => {
    const c = (shop?.catalogs ?? []).find((x) => x.title.toLowerCase() === (title ?? '').toLowerCase());
    return c ? Number(c.price) || 0 : 0;
  };

  // Speak the assistant's reply aloud; a new clip interrupts any previous one.
  function speakReply(text: string) {
    if (!text) return;
    speak(text, 'nova')
      .then((url) => {
        replyAudioRef.current?.pause();
        setSpeaking(true);
        const a = new Audio(url);
        replyAudioRef.current = a;
        a.onended = () => { setSpeaking(false); URL.revokeObjectURL(url); };
        a.onerror = () => setSpeaking(false);
        return a.play();
      })
      .catch(() => setSpeaking(false));
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
    const merged = { ...fieldsRef.current, ...r.fields };
    fieldsRef.current = merged;
    speakReply(r.reply_text);
    const complete = !!(merged.service && merged.date && merged.start_time && merged.customer_name && merged.customer_phone);
    if (r.ready && complete) await book(merged);
  }

  async function toggleMic() {
    if (!shop || busy) return;
    if (recording) {
      setBusy(true);
      const blob = await stop();
      if (!blob) { setBusy(false); return; }
      try {
        await handleReply(await bookAssistantVoice(shop.id, blob, fieldsRef.current));
      } catch {
        speakReply("Sorry, I didn't catch that — please try again.");
      } finally {
        setBusy(false);
      }
    } else {
      try { await start(); } catch { /* mic permission denied — tapping again re-prompts */ }
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
          <button className="c-btn c-btn-block" onClick={() => { fieldsRef.current = { date: todayIso() }; setCreated(null); }}>
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
    </div>
  );
}
