import { Icons } from '@/components/Icons';

export type OrbState = 'idle' | 'listening' | 'thinking' | 'speaking';

/**
 * Presentational voice mic, styled like the home page's centre mic: a clean
 * mint-soft circle. While listening it shows the mic icon with a sonar ring;
 * while the assistant is replying it swaps to voice bars so it's obviously the
 * AI's turn. `level` (0–1) makes it swell slightly to the caller's voice.
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
      {state === 'speaking'
        ? <span className="pb-bars" aria-hidden><i /><i /><i /><i /><i /></span>
        : <Icons.Mic size={44} />}
    </button>
  );
}
