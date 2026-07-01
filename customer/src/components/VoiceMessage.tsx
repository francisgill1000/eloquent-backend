import { useRef, useState } from 'react';
import { Icons } from './Icons';

// A fixed, voice-note-ish waveform (bar heights in %). Decorative — the audio is
// cross-origin so it can't be analysed — but progress is accurate: bars left of
// the playhead light up.
const WAVE = [
  28, 46, 68, 40, 84, 56, 100, 72, 50, 88, 62, 34, 76, 92,
  54, 70, 44, 82, 60, 38, 66, 90, 48, 74, 58, 42, 80, 52,
];

function fmt(sec: number): string {
  const s = Number.isFinite(sec) && sec > 0 ? sec : 0;
  const m = Math.floor(s / 60);
  const r = Math.floor(s % 60);
  return `${m}:${r.toString().padStart(2, '0')}`;
}

/** A custom, on-vibe voice-message player used for both sent and received audio. */
export function VoiceMessage(
  { src, onSpeakingChange }: { src: string; onSpeakingChange?: (playing: boolean) => void },
) {
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const [playing, setPlaying] = useState(false);
  const [progress, setProgress] = useState(0); // 0..1
  const [elapsed, setElapsed] = useState(0);
  const [duration, setDuration] = useState(0);

  const toggle = () => {
    const a = audioRef.current;
    if (!a) return;
    if (a.paused) void a.play()?.catch(() => undefined);
    else a.pause();
  };

  const started = playing || elapsed > 0;

  return (
    <div className="c-vm">
      <button
        type="button"
        className="c-vm-btn"
        onClick={toggle}
        aria-label={playing ? 'Pause voice message' : 'Play voice message'}
      >
        {playing ? <Icons.Pause size={15} /> : <Icons.Play size={15} />}
      </button>
      <div className="c-vm-wave" aria-hidden="true">
        {WAVE.map((h, i) => (
          <span
            key={i}
            className={(i + 0.5) / WAVE.length <= progress ? 'on' : ''}
            style={{ height: `${h}%` }}
          />
        ))}
      </div>
      <span className="c-vm-time">{fmt(started ? elapsed : duration)}</span>
      <audio
        ref={audioRef}
        src={src}
        preload="metadata"
        onLoadedMetadata={(e) => {
          const d = e.currentTarget.duration;
          setDuration(Number.isFinite(d) ? d : 0);
        }}
        onPlay={() => { setPlaying(true); onSpeakingChange?.(true); }}
        onPause={() => { setPlaying(false); onSpeakingChange?.(false); }}
        onEnded={() => { setPlaying(false); setProgress(0); setElapsed(0); onSpeakingChange?.(false); }}
        onTimeUpdate={(e) => {
          const a = e.currentTarget;
          if (Number.isFinite(a.duration) && a.duration > 0) setProgress(a.currentTime / a.duration);
          setElapsed(a.currentTime);
        }}
      />
    </div>
  );
}
