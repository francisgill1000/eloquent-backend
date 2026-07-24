import { EmptyState } from './EmptyState';
import { fmtNum } from '@/lib/dateRange';

export type Seg = { key: string; label: string; value: number; color: string };

/** Proportional ring with a value legend. Empty state when everything is zero. */
export function Donut({ segments, cap, emptyText = 'Nothing to break down yet.' }: {
  segments: Seg[]; cap: string; emptyText?: string;
}) {
  const total = segments.reduce((s, x) => s + x.value, 0);
  const r = 52, cx = 66, cy = 66, sw = 16, Circ = 2 * Math.PI * r;
  if (total === 0) return <EmptyState text={emptyText} />;
  let offset = 0;
  return (
    <div className="ins-donut-row">
      <div className="ins-donut" aria-hidden="true">
        <svg viewBox="0 0 132 132">
          <circle cx={cx} cy={cy} r={r} fill="none" stroke="var(--neutral-soft)" strokeWidth={sw} />
          {segments.filter((s) => s.value > 0).map((s) => {
            const dash = (s.value / total) * Circ;
            const el = (
              <circle key={s.key} className="ins-donut-seg" cx={cx} cy={cy} r={r} fill="none"
                stroke={s.color} strokeWidth={sw} strokeLinecap="butt"
                strokeDasharray={`${Math.max(dash - 2, 0.001)} ${Circ}`} strokeDashoffset={-offset}>
                <title>{`${s.label}: ${fmtNum(s.value)} (${Math.round((s.value / total) * 100)}%)`}</title>
              </circle>
            );
            offset += dash;
            return el;
          })}
        </svg>
        <div className="ins-donut-center">
          <span className="ins-donut-total">{fmtNum(total)}</span>
          <span className="ins-donut-cap">{cap}</span>
        </div>
      </div>
      <div className="ins-legend">
        {segments.map((s) => (
          <div key={s.key} className="ins-legend-item">
            <span className="ins-legend-dot" style={{ background: s.color }} />
            <span className="ins-legend-lab">{s.label}</span>
            <span className="ins-legend-val">{fmtNum(s.value)}</span>
            <span className="ins-legend-pct">{total ? Math.round((s.value / total) * 100) : 0}%</span>
          </div>
        ))}
      </div>
    </div>
  );
}
