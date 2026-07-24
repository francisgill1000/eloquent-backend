import { useMemo, useState } from 'react';
import { EmptyState } from './EmptyState';
import { fmtNum, fmtShort, fmtLong } from '@/lib/dateRange';

export type TrendPoint = { date: string; value: number };
export type TrendSeries = { key: string; label: string; color: string; points: TrendPoint[] };

/**
 * Bucket a series down to at most `maxPoints` by summing consecutive days, so a
 * year-long range stays readable. Returns `bucketed` so the tooltip can say
 * "week of" instead of a single date.
 */
function downsample(points: TrendPoint[], maxPoints = 45): { points: TrendPoint[]; bucketed: boolean } {
  if (points.length <= maxPoints) return { points, bucketed: false };
  const size = Math.ceil(points.length / maxPoints);
  const out: TrendPoint[] = [];
  for (let i = 0; i < points.length; i += size) {
    const chunk = points.slice(i, i + size);
    out.push({ date: chunk[0].date, value: chunk.reduce((s, c) => s + c.value, 0) });
  }
  return { points: out, bucketed: true };
}

export function TrendChart({ series, emptyText = 'Nothing to plot in this range yet.' }: {
  series: TrendSeries[]; emptyText?: string;
}) {
  const [hover, setHover] = useState<number | null>(null);

  const reduced = useMemo(
    () => series.map((s) => ({ ...s, ...downsample(s.points) })),
    [series],
  );

  const n = reduced[0]?.points.length ?? 0;
  const bucketed = reduced[0]?.bucketed ?? false;
  const grand = reduced.reduce((s, ser) => s + ser.points.reduce((a, p) => a + p.value, 0), 0);
  if (n === 0 || grand === 0) return <EmptyState text={emptyText} />;

  const W = 600, H = 200, top = 18, bottom = 26;
  const plotH = H - top - bottom;
  const maxY = Math.max(1, ...reduced.flatMap((s) => s.points.map((p) => p.value)));
  const niceMax = Math.ceil(maxY / 4) * 4 || 4;
  const x = (i: number) => (n === 1 ? W / 2 : (i / (n - 1)) * W);
  const y = (v: number) => top + plotH * (1 - v / niceMax);

  const path = (pts: TrendPoint[]) =>
    pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' ');

  const gridVals = [0, niceMax / 2, niceMax];

  const onMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const r = e.currentTarget.getBoundingClientRect();
    const frac = (e.clientX - r.left) / r.width;
    setHover(Math.max(0, Math.min(n - 1, Math.round(frac * (n - 1)))));
  };

  const label = series.map((s) => s.label).join(' and ');

  return (
    <div className="ins-chartbox" onPointerMove={onMove} onPointerLeave={() => setHover(null)}>
      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" role="img" aria-label={`${label} over time`}>
        <defs>
          <linearGradient id="insTrend" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={reduced[0].color} stopOpacity="0.34" />
            <stop offset="100%" stopColor={reduced[0].color} stopOpacity="0.02" />
          </linearGradient>
        </defs>
        {gridVals.map((v, i) => (
          <g key={i}>
            <line className="ins-grid-line" x1={0} x2={W} y1={y(v)} y2={y(v)} />
            <text className="ins-axis-lab" x={2} y={y(v) - 3}>{Math.round(v)}</text>
          </g>
        ))}
        {/* The first series gets the filled area, the rest are lines. */}
        <path d={`${path(reduced[0].points)} L${x(n - 1).toFixed(1)},${top + plotH} L${x(0).toFixed(1)},${top + plotH} Z`} fill="url(#insTrend)" />
        {reduced.map((s) => (
          <path key={s.key} d={path(s.points)} fill="none" stroke={s.color} strokeWidth={2}
            strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        ))}
        {hover !== null && (
          <g>
            <line className="ins-grid-line" x1={x(hover)} x2={x(hover)} y1={top} y2={top + plotH} stroke="var(--border-3)" />
            {reduced.map((s) => (
              <circle key={s.key} cx={x(hover)} cy={y(s.points[hover].value)} r={4}
                fill={s.color} stroke="var(--bg-1)" strokeWidth={2} vectorEffect="non-scaling-stroke" />
            ))}
          </g>
        )}
        {[0, Math.floor((n - 1) / 2), n - 1].filter((v, i, a) => a.indexOf(v) === i && v >= 0).map((i) => (
          <text key={i} className="ins-axis-lab" x={Math.max(12, Math.min(W - 12, x(i)))} y={H - 8}
            textAnchor={i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}>{fmtShort(reduced[0].points[i].date)}</text>
        ))}
      </svg>
      {hover !== null && (
        <div className="ins-tooltip" style={{ left: `${(x(hover) / W) * 100}%`, top: `${(y(reduced[0].points[hover].value) / H) * 100}%` }}>
          <div className="ins-tt-date">{bucketed ? `Week of ${fmtShort(reduced[0].points[hover].date)}` : fmtLong(reduced[0].points[hover].date)}</div>
          {reduced.map((s) => (
            <div key={s.key} className="ins-tt-val">
              <span className="d">{fmtNum(s.points[hover].value)}</span> {s.label}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
