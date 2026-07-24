import { PRESETS, fmtLong, daysBetween, type PresetKey } from '@/lib/dateRange';

/** Quick-range segmented control plus a custom from/to pair. */
export function RangeFilter({ preset, from, to, onPreset, onFrom, onTo }: {
  preset: PresetKey;
  from: string;
  to: string;
  onPreset: (key: Exclude<PresetKey, 'custom'>) => void;
  onFrom: (v: string) => void;
  onTo: (v: string) => void;
}) {
  const len = daysBetween(from, to);
  return (
    <div className="ins-filter">
      <div className="ins-seg" role="group" aria-label="Quick ranges">
        {PRESETS.map((p) => (
          <button key={p.key} className={`ins-seg-btn${preset === p.key ? ' on' : ''}`}
            aria-pressed={preset === p.key} onClick={() => onPreset(p.key)}>{p.label}</button>
        ))}
      </div>
      <div className="ins-range-row">
        <span className="ins-range-lab">Custom</span>
        <input className="ins-date-input" type="date" value={from} max={to}
          onChange={(e) => onFrom(e.target.value)} aria-label="From date" />
        <span className="ins-date-dash">→</span>
        <input className="ins-date-input" type="date" value={to} min={from}
          onChange={(e) => onTo(e.target.value)} aria-label="To date" />
        <span className="ins-active"><b>{fmtLong(from)}</b> – <b>{fmtLong(to)}</b> · {len} day{len === 1 ? '' : 's'}</span>
      </div>
    </div>
  );
}
