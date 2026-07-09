import { Icons } from '@/components/Icons';

export type OrbState = 'idle' | 'listening' | 'thinking' | 'speaking';

/**
 * Presentational voice orb. The state drives its colour, glow and which
 * indicator shows: an equalizer while listening, bouncing dots while thinking,
 * the mic icon otherwise. `level` (0–1) makes it swell to the caller's voice in
 * tap mode; hands-free relies on the always-moving equalizer instead.
 */
export function VoiceOrb({ state, level, ariaLabel, disabled, onTap }: {
  state: OrbState;
  level: number;
  ariaLabel: string;
  disabled: boolean;
  onTap: () => void;
}) {
  return (
    <button
      className={`pb-mic pb-mic-${state}`}
      style={{ ['--lvl' as string]: level.toFixed(3) }}
      aria-label={ariaLabel}
      disabled={disabled}
      onClick={onTap}
    >
      <span className="pb-mic-ring" aria-hidden />
      <span className="pb-mic-ring pb-mic-ring2" aria-hidden />
      <span className="pb-eq" aria-hidden><i /><i /><i /><i /><i /></span>
      <span className="pb-dots" aria-hidden><i /><i /><i /></span>
      <Icons.Mic size={46} />
    </button>
  );
}
