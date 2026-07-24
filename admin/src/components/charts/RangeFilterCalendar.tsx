import { useState } from 'react';
import { DateRangePicker } from '@/components/DateRangePicker';
import { PRESETS, fmtLong, daysBetween, type PresetKey } from '@/lib/dateRange';

/**
 * Quick-range presets plus a "Custom…" toggle that reveals the same inline
 * calendar range picker used on the AI summary page. The calendar holds its own
 * draft state and only commits on Submit, so half-picked ranges never fire a
 * fetch. The picker floats as an overlay below the toggle (see .ins-custom-wrap).
 */
export function RangeFilterCalendar({ preset, from, to, onPreset, onCustom }: {
  preset: PresetKey;
  from: string;
  to: string;
  onPreset: (key: Exclude<PresetKey, 'custom'>) => void;
  onCustom: (from: string, to: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [draftFrom, setDraftFrom] = useState(from);
  const [draftTo, setDraftTo] = useState(to);
  const len = daysBetween(from, to);

  // Re-clicking Custom toggles the calendar; other openings seed it with the
  // currently applied range so it starts where the user left off.
  const toggleCustom = () => {
    setDraftFrom(from); setDraftTo(to);
    setOpen((o) => !o);
  };

  const submit = () => {
    if (!draftFrom || !draftTo) return;
    onCustom(draftFrom, draftTo);
    setOpen(false);
  };

  return (
    <div className="ins-filter">
      <div className="ins-tabs-row">
        <div className="ins-seg" role="group" aria-label="Quick ranges">
          {PRESETS.map((p) => (
            <button key={p.key} className={`ins-seg-btn${preset === p.key ? ' on' : ''}`}
              aria-pressed={preset === p.key} onClick={() => { setOpen(false); onPreset(p.key); }}>{p.label}</button>
          ))}
          <button className={`ins-seg-btn${preset === 'custom' && open ? ' on' : ''}`}
            aria-pressed={preset === 'custom'} onClick={toggleCustom}>Custom…</button>
        </div>

        {/* Kept mounted so it can animate open/closed; aria-hidden keeps the
            collapsed picker out of the a11y tree. */}
        <div className={`ins-custom-wrap${open ? ' is-open' : ''}`} aria-hidden={!open}>
          <div className="ins-custom">
            <DateRangePicker from={draftFrom} to={draftTo}
              onChange={(f, t) => { setDraftFrom(f); setDraftTo(t); }}
              footer={<button className="drp-go" disabled={!draftFrom || !draftTo}
                onClick={submit}>Submit</button>} />
          </div>
        </div>
      </div>

      <div className="ins-range-row">
        <span className="ins-range-lab">Showing</span>
        <span className="ins-active"><b>{fmtLong(from)}</b> – <b>{fmtLong(to)}</b> · {len} day{len === 1 ? '' : 's'}</span>
      </div>
    </div>
  );
}
