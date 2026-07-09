import { Icons } from '@/components/Icons';

export type OrbState = 'idle' | 'listening' | 'thinking' | 'speaking';

/**
 * Presentational voice mic, styled like the home page's centre mic: a clean
 * mint-soft circle with a mic icon. Active states pulse; `level` (0–1) makes it
 * swell slightly to the caller's voice in tap mode.
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
      <Icons.Mic size={44} />
    </button>
  );
}
