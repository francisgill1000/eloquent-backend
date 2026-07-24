import type { ReactNode } from 'react';

/**
 * The vs-previous-period indicator. `goodDir` says which direction is good, so
 * a falling no-show rate reads as green and a falling revenue reads as red.
 */
export function Delta({ change, display, goodDir }: {
  change: number | null; display: string; goodDir: 'up' | 'down';
}) {
  if (change === null) return <span className="ins-kpi-delta flat"><span className="vs">no prior data</span></span>;
  const arrow = change > 0 ? '▲' : change < 0 ? '▼' : '—';
  const cls = change === 0 ? 'flat' : (change > 0) === (goodDir === 'up') ? 'up' : 'down';
  return <span className={`ins-kpi-delta ${cls}`}>{arrow} {display} <span className="vs">vs prev</span></span>;
}

export function Kpi({ label, value, unit, delta }: {
  label: string; value: string; unit?: string; delta: ReactNode;
}) {
  return (
    <div className="ins-kpi">
      <span className="ins-kpi-label">{label}</span>
      <span className="ins-kpi-value">{value}{unit && <span className="u">{unit}</span>}</span>
      {delta}
    </div>
  );
}
