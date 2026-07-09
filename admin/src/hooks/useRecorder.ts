import { useEffect, useRef, useState } from 'react';

function pickMime(): string | undefined {
  const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
  for (const c of candidates) {
    if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported?.(c)) return c;
  }
  return undefined;
}

export function useRecorder(opts?: {
  meter?: boolean;
  // Voice-activity auto-stop: fires once the caller has spoken and then gone
  // quiet for `silenceMs`. Uses the same level meter, so requires meter: true.
  onSilence?: () => void;
  silenceMs?: number;
  speechThreshold?: number;
  maxMs?: number;
}) {
  const recorderRef = useRef<MediaRecorder | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const [recording, setRecording] = useState(false);
  const [level, setLevel] = useState(0);
  const ctxRef = useRef<AudioContext | null>(null);
  const rafRef = useRef<number | null>(null);
  // Keep the latest onSilence callback so the meter loop never calls a stale one.
  const onSilenceRef = useRef(opts?.onSilence);
  onSilenceRef.current = opts?.onSilence;
  const supported = typeof navigator !== 'undefined' && !!navigator.mediaDevices && typeof MediaRecorder !== 'undefined';

  function startMeter(stream: MediaStream) {
    try {
      const Ctx: typeof AudioContext = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
      const ctx = new Ctx();
      ctxRef.current = ctx;
      const analyser = ctx.createAnalyser();
      analyser.fftSize = 256;
      ctx.createMediaStreamSource(stream).connect(analyser);
      const buf = new Uint8Array(analyser.frequencyBinCount);
      // Smooth the raw RMS with an asymmetric envelope follower — quick to rise
      // when the caller speaks, slow to fall — so the mic swells and settles
      // instead of flickering frame-to-frame. Only push a new value when it
      // moves enough to matter, letting the CSS transition glide between them.
      let smoothed = 0;
      let lastEmit = -1;
      // Voice-activity state: don't auto-stop until the caller has actually
      // spoken, then stop after a run of silence (or a hard max length).
      const speech = opts?.speechThreshold ?? 0.12;
      const silenceMs = opts?.silenceMs ?? 1300;
      const maxMs = opts?.maxMs ?? 15000;
      let hasSpoken = false;
      let fired = false;
      const startedAt = performance.now();
      let lastLoud = startedAt;
      const tick = () => {
        analyser.getByteTimeDomainData(buf);
        let sum = 0;
        for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; sum += v * v; }
        const target = Math.min(1, Math.sqrt(sum / buf.length) * 2.6);
        smoothed += (target - smoothed) * (target > smoothed ? 0.35 : 0.12);
        if (Math.abs(smoothed - lastEmit) > 0.012) { lastEmit = smoothed; setLevel(smoothed); }

        const now = performance.now();
        if (smoothed > speech) { hasSpoken = true; lastLoud = now; }
        const quietDone = hasSpoken && (now - lastLoud) > silenceMs;
        const tooLong = (now - startedAt) > maxMs;
        if (!fired && onSilenceRef.current && (quietDone || tooLong)) {
          fired = true;
          onSilenceRef.current();
        }
        rafRef.current = requestAnimationFrame(tick);
      };
      tick();
    } catch { /* metering is best-effort */ }
  }

  function stopMeter() {
    if (rafRef.current != null) cancelAnimationFrame(rafRef.current);
    rafRef.current = null;
    ctxRef.current?.close().catch(() => undefined);
    ctxRef.current = null;
    setLevel(0);
  }

  useEffect(() => () => {
    // Release mic + audio graph if the component unmounts mid-recording.
    try { recorderRef.current?.state !== 'inactive' && recorderRef.current?.stop(); } catch { /* already stopped */ }
    streamRef.current?.getTracks().forEach((t) => t.stop());
    stopMeter();
  }, []);

  async function start(): Promise<void> {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    streamRef.current = stream;
    const mime = pickMime();
    const rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
    chunksRef.current = [];
    rec.ondataavailable = (e) => { if (e.data.size > 0) chunksRef.current.push(e.data); };
    recorderRef.current = rec;
    rec.start();
    setRecording(true);
    if (opts?.meter) startMeter(stream);
  }

  function stop(): Promise<Blob | null> {
    return new Promise((resolve) => {
      const rec = recorderRef.current;
      if (!rec) { resolve(null); return; }
      rec.onstop = () => {
        rec.stream.getTracks().forEach((t) => t.stop());
        stopMeter();
        const blob = new Blob(chunksRef.current, { type: rec.mimeType || 'audio/webm' });
        setRecording(false);
        resolve(blob.size > 0 ? blob : null);
      };
      rec.stop();
    });
  }

  return { recording, start, stop, supported, level };
}
