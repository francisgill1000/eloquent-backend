import { Icons } from './Icons';

export type MicState = 'idle' | 'listening' | 'thinking';

/**
 * Futuristic voice trigger: a glowing mint mic core wrapped in concentric
 * outline rings. The rings pulse outward (radar style) while listening, the
 * core breathes when idle, and a spinner replaces the mic while thinking.
 * Pure presentation — all motion is CSS (see the c-mic-* block in customer.css).
 */
export function VoiceMic({ state, onClick }: { state: MicState; onClick: () => void }) {
  const label = state === 'listening' ? 'Stop listening' : 'Start voice search';
  return (
    <button type="button" className={`c-mic state-${state}`} aria-label={label} onClick={onClick}>
      <span className="c-mic-ring r1" aria-hidden="true" />
      <span className="c-mic-ring r2" aria-hidden="true" />
      <span className="c-mic-ring r3" aria-hidden="true" />
      <span className="c-mic-core">
        {state === 'thinking' ? <span className="c-mic-spin" /> : <Icons.Mic size={26} />}
      </span>
    </button>
  );
}
