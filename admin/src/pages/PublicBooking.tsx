import { useEffect, useMemo, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getPublicShop, bookAssistantText, bookAssistantVoice, type BookingFields, type PublicShop } from '@/lib/publicBooking';
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

export default function PublicBooking() {
  const { shopId } = useParams<{ shopId: string }>();
  const id = Number(shopId);

  const [shop, setShop] = useState<PublicShop | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [fields, setFields] = useState<BookingFields>({ date: todayIso() });
  const [booking, setBooking] = useState(false);
  const [error, setError] = useState('');
  const [created, setCreated] = useState<Created | null>(null);

  const { recording, start, stop, supported, level } = useRecorder({ meter: true });
  const [busy, setBusy] = useState(false);
  const [reply, setReply] = useState('');
  const [speaking, setSpeaking] = useState(false);
  const [draft, setDraft] = useState('');
  const replyAudioRef = useRef<HTMLAudioElement | null>(null);

  useEffect(() => {
    let alive = true;
    getPublicShop(id)
      .then((s) => { if (alive) setShop(s); })
      .catch(() => { if (alive) setLoadError(true); });
    return () => { alive = false; };
  }, [id]);

  const set = <K extends keyof BookingFields>(k: K, v: BookingFields[K]) => setFields((f) => ({ ...f, [k]: v }));

  async function applyReply(r: { reply_text: string; fields: BookingFields }) {
    setFields((f) => ({ ...f, ...r.fields }));
    setReply(r.reply_text);
    if (r.reply_text) {
      try {
        const url = await speak(r.reply_text, 'nova');
        replyAudioRef.current?.pause();
        setSpeaking(true);
        const a = new Audio(url);
        replyAudioRef.current = a;
        a.onended = () => { setSpeaking(false); URL.revokeObjectURL(url); };
        a.onerror = () => setSpeaking(false);
        await a.play();
      } catch { setSpeaking(false); }
    }
  }

  async function sendText() {
    if (!draft.trim() || busy || !shop) return;
    setBusy(true); setError('');
    try { await applyReply(await bookAssistantText(shop.id, draft, fields)); setDraft(''); }
    catch { setError('Could not reach the assistant.'); }
    finally { setBusy(false); }
  }

  async function toggleMic() {
    if (!shop) return;
    if (recording) {
      setBusy(true);
      const blob = await stop();
      if (!blob) { setBusy(false); return; }
      try { await applyReply(await bookAssistantVoice(shop.id, blob, fields)); }
      catch { setError('Could not reach the assistant.'); }
      finally { setBusy(false); }
    } else {
      setError('');
      try { await start(); } catch { setError('Microphone permission needed.'); }
    }
  }

  const micState = recording ? 'listening' : busy ? 'thinking' : speaking ? 'speaking' : 'idle';

  const catalogs = shop?.catalogs ?? [];
  const priceFor = (title?: string): number => {
    const c = catalogs.find((x) => x.title.toLowerCase() === (title ?? '').toLowerCase());
    return c ? Number(c.price) || 0 : 0;
  };

  const ready = useMemo(() =>
    !!(fields.service && fields.date && fields.start_time && fields.customer_name && fields.customer_phone),
    [fields]);

  async function confirm() {
    if (!ready || !shop) return;
    setBooking(true); setError('');
    try {
      await createBooking(shop.id, {
        services: [{ title: fields.service!, price: priceFor(fields.service) }],
        charges: priceFor(fields.service),
        date: fields.date!,
        start_time: fields.start_time!,
        customer_name: fields.customer_name!,
        customer_whatsapp: fields.customer_phone!,
      });
      setCreated({ service: fields.service!, date: fields.date!, start_time: fields.start_time!, customer_name: fields.customer_name! });
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg && /closed/i.test(msg) ? "We're closed then — please pick another time." : (msg || 'Could not book right now — please try again.'));
    } finally {
      setBooking(false);
    }
  }

  if (loadError) {
    return <div className="pb-screen"><div className="pb-empty"><Icons.Store size={28} /><p>This booking link isn't available right now.</p></div></div>;
  }
  if (!shop) {
    return <div className="pb-screen"><div className="pb-empty"><p>Loading…</p></div></div>;
  }
  if (created) {
    return (
      <div className="pb-screen">
        <div className="pb-done">
          <div className="pb-done-tick"><Icons.Check size={30} /></div>
          <h2>You're booked!</h2>
          <p className="pb-done-sub">{created.service} · {created.date} at {created.start_time}</p>
          <p className="pb-done-sub">See you soon, {created.customer_name} — {shop.name}.</p>
          <button className="c-btn c-btn-block" onClick={() => { setCreated(null); setFields({ date: todayIso() }); }}>
            Book another
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="pb-screen">
      <header className="pb-head">
        {shop.logo ? <img className="pb-logo" src={shop.logo} alt="" /> : <span className="pb-logo pb-logo-empty">{shop.name.slice(0, 1)}</span>}
        <div><div className="pb-title">Book with {shop.name}</div><div className="pb-sub">Pick your service and time, or use the mic.</div></div>
      </header>

      <div className="pb-body pb-has-mic">
        <div className="pb-voice">
          <button
            className={`pb-mic pb-mic-${micState}`}
            style={{ ['--lvl' as string]: recording ? level.toFixed(3) : 0 }}
            aria-label={recording ? 'Stop' : 'Speak to book'}
            disabled={!supported || (busy && !recording)}
            onClick={() => void toggleMic()}
          >
            <span className="pb-mic-ring" aria-hidden />
            <span className="pb-mic-ring pb-mic-ring2" aria-hidden />
            <Icons.Mic size={34} />
          </button>
          <p className="pb-voice-cap">
            {micState === 'listening' ? 'Listening…' : micState === 'thinking' ? 'One sec…' : micState === 'speaking' ? 'Speaking…' : 'Tap and tell me what you need'}
          </p>
          {reply && <p className="pb-voice-reply">{reply}</p>}
          <div className="pb-voice-type">
            <input className="pb-input" placeholder="…or tell me what you'd like to book"
              value={draft} onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') void sendText(); }} disabled={busy} />
            <button className="c-btn" aria-label="Send" disabled={busy || !draft.trim()} onClick={() => void sendText()}>
              <Icons.Send size={16} />
            </button>
          </div>
        </div>

        <div className="pb-form">
          <label className="c-field-label">Service</label>
          <div className="pb-chips">
            {catalogs.map((c) => (
              <button key={c.id} type="button"
                className={`pb-chip ${fields.service === c.title ? 'is-on' : ''}`}
                onClick={() => set('service', c.title)}>
                {c.title}<span className="pb-chip-price">AED {c.price}</span>
              </button>
            ))}
          </div>

          <label className="c-field-label" htmlFor="pb-date">Date</label>
          <input id="pb-date" className="pb-input" type="date" value={fields.date ?? ''} onChange={(e) => set('date', e.target.value)} />

          <label className="c-field-label" htmlFor="pb-time">Time</label>
          <input id="pb-time" className="pb-input" type="time" value={fields.start_time ?? ''} onChange={(e) => set('start_time', e.target.value)} />

          <label className="c-field-label" htmlFor="pb-name">Your name</label>
          <input id="pb-name" className="pb-input" type="text" value={fields.customer_name ?? ''} onChange={(e) => set('customer_name', e.target.value)} />

          <label className="c-field-label" htmlFor="pb-phone">Phone (WhatsApp)</label>
          <input id="pb-phone" className="pb-input" type="tel" value={fields.customer_phone ?? ''} onChange={(e) => set('customer_phone', e.target.value)} />

          {error && <div className="c-error-box">{error}</div>}

          <button className="c-btn c-btn-block" disabled={!ready || booking} onClick={() => void confirm()}>
            {booking ? 'Booking…' : 'Confirm booking'}
          </button>
        </div>
      </div>
    </div>
  );
}
