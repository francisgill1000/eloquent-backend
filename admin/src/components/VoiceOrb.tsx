import { Icons } from '@/components/Icons';

export type OrbState = 'idle' | 'listening' | 'thinking' | 'speaking';

/**
 * Presentational voice orb. Idle shows the mic icon; every active state
 * (listening / thinking / speaking) shows a Knight-Rider scanner that sweeps a
 * bright cluster across dim LED segments, tinted by the state's colour. `level`
 * (0–1) makes the orb swell to the caller's voice in tap mode.
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
      <span className="pb-scan" aria-hidden>
        <i /><i /><i /><i /><i /><i /><i /><i /><i />
      </span>
      <Icons.Mic size={46} />
    </button>
  );
}
