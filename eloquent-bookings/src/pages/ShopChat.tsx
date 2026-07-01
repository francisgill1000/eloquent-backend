import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import api from '@/lib/api';
import { getChatMessages, sendChatMessage, sendChatVoice } from '@/lib/chat';
import { linkify } from '@/lib/linkify';
import { toSpeech, SERVICES_TOKEN } from '@/lib/voice';
import { Icons } from '@/components/Icons';
import { Spinner } from '@/components/Spinner';
import { VoiceMessage } from '@/components/VoiceMessage';
import { ServicesList } from '@/components/ServicesList';
import { ServicesSheet } from '@/components/ServicesSheet';
import { type OrbState } from '@/components/AiCoreOrb';
import type { ChatMessage, Service } from '@/types';

const POLL_MS = 4000;

function bubbleTime(iso?: string): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/** The recorder MIME this browser supports (Chrome: webm, Safari: mp4). */
function pickAudioMime(): string {
  const cands = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
  for (const c of cands) {
    if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported?.(c)) return c;
  }
  return '';
}

export default function ShopChat() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const shopId = Number(id);
  const navState = useLocation().state as { shopName?: string; shopLogo?: string } | null;
  const stateShopName = navState?.shopName;

  const [shopName, setShopName] = useState(stateShopName ?? '');
  const [shopLogo, setShopLogo] = useState(navState?.shopLogo ?? '');
  const [services, setServices] = useState<Service[]>([]);
  const [showServices, setShowServices] = useState(false);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [recording, setRecording] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [awaitingReply, setAwaitingReply] = useState(false);
  const [speaking, setSpeaking] = useState(false);
  const [voiceOn] = useState(true);

  const lastIdRef = useRef(0);
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const recorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const outCountRef = useRef(0);
  const ttsAudioRef = useRef<HTMLAudioElement | null>(null);
  const speakSeqRef = useRef(0); // invalidates older, in-flight /tts so clips never overlap
  const spokenIdRef = useRef(0);
  const ttsInitRef = useRef(false);
  const voiceOnRef = useRef(true);

  const appendMessages = useCallback((incoming: ChatMessage[]) => {
    if (incoming.length === 0) return;
    setMessages((prev) => {
      const seen = new Set(prev.map((m) => m.id));
      const fresh = incoming.filter((m) => !seen.has(m.id));
      if (fresh.length === 0) return prev;
      return [...prev, ...fresh];
    });
    const maxId = Math.max(...incoming.map((m) => m.id));
    if (maxId > lastIdRef.current) lastIdRef.current = maxId;
  }, []);

  // initial load: history (+ shop name when not passed via navigation state)
  useEffect(() => {
    if (!shopId) return;
    let alive = true;
    (async () => {
      try {
        const history = await getChatMessages(shopId);
        if (!alive) return;
        appendMessages(history);
      } catch {
        if (alive) setError('Could not load this chat.');
      } finally {
        if (alive) setLoading(false);
      }
    })();
    // Always fetch the shop: we need its services for the in-chat list, and we
    // fill in the name/logo too when they weren't handed over via navigation
    // state (e.g. a direct link).
    void api.get(`/shops/${shopId}`)
      .then((res) => {
        if (!alive) return;
        const shop = res.data?.data ?? res.data;
        if (!stateShopName) setShopName(shop?.name ?? '');
        if (!navState?.shopLogo) setShopLogo(shop?.logo ?? '');
        setServices(Array.isArray(shop?.catalogs) ? shop.catalogs : []);
      })
      .catch(() => undefined);
    return () => { alive = false; };
  }, [shopId, stateShopName, appendMessages]);

  // poll for replies
  useEffect(() => {
    if (!shopId) return;
    const timer = setInterval(async () => {
      try {
        const fresh = await getChatMessages(shopId, lastIdRef.current);
        appendMessages(fresh);
      } catch {
        /* transient poll error — next tick retries */
      }
    }, POLL_MS);
    return () => clearInterval(timer);
  }, [shopId, appendMessages]);

  // auto-scroll on new messages
  useEffect(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages.length]);

  // The orb is "thinking" from when I send until the AI's next reply lands.
  useEffect(() => {
    const outCount = messages.reduce((n, m) => (m.direction === 'out' ? n + 1 : n), 0);
    if (outCount > outCountRef.current) setAwaitingReply(false);
    outCountRef.current = outCount;
  }, [messages]);

  const handleSend = async () => {
    const text = draft.trim();
    if (!text || sending) return;
    setSending(true);
    setError('');
    try {
      const sent = await sendChatMessage(shopId, text);
      appendMessages([sent]);
      setDraft('');
      setAwaitingReply(true);
    } catch {
      setError('Could not send. Please try again.');
    } finally {
      setSending(false);
    }
  };

  const startRecording = async () => {
    if (recording || uploading) return;
    setError('');
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mime = pickAudioMime();
      const rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
      chunksRef.current = [];
      rec.ondataavailable = (e) => { if (e.data.size > 0) chunksRef.current.push(e.data); };
      rec.onstop = () => {
        stream.getTracks().forEach((t) => t.stop());
        const blob = new Blob(chunksRef.current, { type: rec.mimeType || 'audio/webm' });
        void uploadVoice(blob);
      };
      recorderRef.current = rec;
      rec.start();
      setRecording(true);
    } catch {
      setError('Microphone access is needed to record. Please allow it and try again.');
    }
  };

  const stopRecording = () => {
    if (!recording) return;
    setRecording(false);
    recorderRef.current?.stop();
  };

  const uploadVoice = async (blob: Blob) => {
    if (blob.size === 0) return;
    setUploading(true);
    setError('');
    try {
      const sent = await sendChatVoice(shopId, blob);
      appendMessages([sent]);
      setAwaitingReply(true);
    } catch {
      setError('Could not send your voice note. Please try again.');
    } finally {
      setUploading(false);
    }
  };

  // --- Voice: speak the assistant's replies aloud (OpenAI TTS via /tts) ---

  const stopSpeaking = useCallback(() => {
    speakSeqRef.current++; // cancel any /tts fetch still in flight
    ttsAudioRef.current?.pause();
    ttsAudioRef.current = null;
    setSpeaking(false);
  }, []);

  // Speak a reply ONLY through the in-thread OpenAI voice (one voice, in chat).
  // No browser speechSynthesis fallback — that produced a second, overlapping
  // "notification" voice. If /tts is unavailable, the reply stays silent.
  const speakText = useCallback(async (text: string) => {
    stopSpeaking();                    // stop prior clip + invalidate its in-flight fetch
    const seq = ++speakSeqRef.current; // claim this run; a newer reply will supersede it
    try {
      const { data } = await api.post('/tts', { text }, { responseType: 'blob' });
      // Bail if muted, or a newer reply started speaking while /tts was in flight
      // (otherwise this older clip would play on top of the newer one).
      if (seq !== speakSeqRef.current || !voiceOnRef.current) return;
      const url = URL.createObjectURL(data as Blob);
      const audio = new Audio(url);
      ttsAudioRef.current = audio;
      audio.onplay = () => setSpeaking(true);
      audio.onended = () => { setSpeaking(false); URL.revokeObjectURL(url); };
      audio.onerror = () => setSpeaking(false);
      await audio.play().catch(() => setSpeaking(false));
    } catch {
      setSpeaking(false);
    }
  }, [stopSpeaking]);

  // Speak each NEW assistant reply once it lands (not the history on open, and
  // only while voice is on). 'out' = the AI/salon side; strip a leading emoji.
  useEffect(() => {
    if (loading) return;
    let latest: ChatMessage | undefined;
    for (let i = messages.length - 1; i >= 0; i--) {
      if (messages[i].direction === 'out') { latest = messages[i]; break; }
    }
    const latestId = latest?.id ?? 0;
    if (!ttsInitRef.current) {
      // First settle after load — don't read existing history aloud.
      ttsInitRef.current = true;
      spokenIdRef.current = latestId;
      return;
    }
    if (latestId <= spokenIdRef.current) return;
    spokenIdRef.current = latestId;
    if (!voiceOn || !latest) return;
    // Voice notes have their own in-bubble player — don't also read them aloud.
    const isAudio = !!latest.media_url && (latest.type === 'audio' || latest.type === 'voice');
    if (isAudio) return;
    const text = toSpeech(latest.body);
    if (text) void speakText(text);
  }, [messages, loading, voiceOn, speakText]);

  // Keep a ref of the latest voice state for async callbacks (in-flight /tts).
  useEffect(() => { voiceOnRef.current = voiceOn; }, [voiceOn]);

  // Muting cuts off whatever is currently playing, immediately.
  useEffect(() => { if (!voiceOn) stopSpeaking(); }, [voiceOn, stopSpeaking]);

  // Stop any audio when leaving the chat.
  useEffect(() => stopSpeaking, [stopSpeaking]);

  const title = shopName || 'Chat';
  const monogram = (Array.from(title)[0] || '?').toUpperCase();
  const orbState: OrbState =
    recording ? 'listening'
      : speaking ? 'talking'
        : (awaitingReply || uploading) ? 'thinking'
          : 'idle';
  const statusText =
    orbState === 'listening' ? 'listening…'
      : orbState === 'thinking' ? 'thinking…'
        : orbState === 'talking' ? 'replying…'
          : 'AI assistant · online';

  return (
    <div className="m-screen c-thread-screen">
      <div className="c-thread-head">
        <button className="c-icon-btn" aria-label="Back" onClick={() => navigate(`/shop/${shopId}`)}>
          <Icons.ChevronLeft size={18} />
        </button>
        <div className="c-thread-avatar">
          {shopLogo ? <img src={shopLogo} alt={title} /> : monogram}
        </div>
        <div className="c-thread-head-text">
          <span className="c-thread-title">{title}</span>
          <span className="c-thread-sub">
            <span className="c-live-dot" />
            {statusText}
          </span>
        </div>
        {services.length > 0 && (
          <button
            className="c-icon-btn"
            style={{ marginLeft: 'auto' }}
            aria-label="View services"
            onClick={() => setShowServices(true)}
          >
            <Icons.List size={18} />
          </button>
        )}
      </div>

      {showServices && <ServicesSheet services={services} onClose={() => setShowServices(false)} />}

      <div className="c-thread-scroll" ref={scrollRef}>
        {loading ? (
          <Spinner />
        ) : messages.length === 0 ? (
          <p className="c-thread-empty">Say hi! Ask about prices, timings or availability.</p>
        ) : (
          messages.map((m) => {
            // direction is from the shop's side: 'in' = sent by me (customer),
            // 'out' = the AI/salon. Audio messages render the voice player.
            const isAudio = !!m.media_url && (m.type === 'audio' || m.type === 'voice');
            const isBot = m.direction === 'out';
            // The assistant marks "show the services list" with [[services]];
            // strip the token from the text and render the priced list below —
            // also when the reply is a voice note (token lives in its body).
            const showSvc = isBot && m.body.includes(SERVICES_TOKEN);
            const text = showSvc ? m.body.split(SERVICES_TOKEN).join('').trim() : m.body;
            return (
              <div key={m.id} className={`c-bubble ${isBot ? 'in' : 'out'}`}>
                {isAudio ? (
                  <VoiceMessage src={m.media_url!} onSpeakingChange={isBot ? setSpeaking : undefined} />
                ) : (
                  text && <span className="c-bubble-text">{linkify(text)}</span>
                )}
                {showSvc && <ServicesList services={services} />}
                <span className="c-bubble-time">{bubbleTime(m.created_at)}</span>
              </div>
            );
          })
        )}
      </div>

      {error && <div className="c-error-box" style={{ margin: '0 16px 8px' }}>{error}</div>}

      <div className="c-composer">
        {recording ? (
          <>
            <span className="c-rec-dot" />
            <span className="c-rec-label">Recording… tap to send</span>
            <button className="c-composer-send" aria-label="Stop and send" onClick={() => stopRecording()}>
              <Icons.Send size={18} />
            </button>
          </>
        ) : (
          <>
            <input
              type="text"
              placeholder={uploading ? 'Sending voice…' : 'Type a message…'}
              value={draft}
              disabled={uploading}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') void handleSend(); }}
            />
            {draft.trim() ? (
              <button className="c-composer-send" aria-label="Send" disabled={sending} onClick={() => void handleSend()}>
                <Icons.Send size={18} />
              </button>
            ) : (
              <button className="c-composer-send" aria-label="Record voice" disabled={uploading} onClick={() => void startRecording()}>
                {uploading ? <span className="c-mini-spin" /> : <Icons.Mic size={18} />}
              </button>
            )}
          </>
        )}
      </div>
    </div>
  );
}
