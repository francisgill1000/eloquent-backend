import { LEAD_CHANNELS } from '@/types';
import type { LeadChannel } from '@/types';
import { CHANNEL_META } from '@/lib/channels';

type Props = {
  open: boolean;
  title: string;
  onPick: (channel: LeadChannel) => void;
  onClose: () => void;
};

/**
 * Small channel chooser, shared by "Log a touch", "They replied", and the move
 * to Replied — so the same nine options are offered wherever a channel is asked
 * for. Dismissing it is always allowed: an unknown channel is honest.
 */
export function ChannelPicker({ open, title, onPick, onClose }: Props) {
  if (!open) return null;

  return (
    <div className="cp-backdrop" role="dialog" aria-label={title} onClick={onClose}>
      <div className="cp-panel" onClick={(e) => e.stopPropagation()}>
        <h3 className="cp-title">{title}</h3>
        <div className="cp-grid">
          {LEAD_CHANNELS.map((channel) => (
            <button
              key={channel}
              type="button"
              className="cp-opt"
              style={{ ['--ch' as string]: CHANNEL_META[channel].color }}
              onClick={() => onPick(channel)}
            >
              {CHANNEL_META[channel].label}
            </button>
          ))}
        </div>
        <button type="button" className="cp-skip" onClick={onClose}>Skip</button>
      </div>
    </div>
  );
}
