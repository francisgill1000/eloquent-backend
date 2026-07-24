/** Labelled percentage bars, clamped to 0-100. */
export function RateBars({ rows }: { rows: { label: string; value: number; color: string }[] }) {
  return (
    <div className="ins-rates">
      {rows.map((r) => (
        <div key={r.label}>
          <div className="ins-rate-head">
            <span className="ins-rate-lab">{r.label}</span>
            <span className="ins-rate-val">{r.value}%</span>
          </div>
          <div className="ins-rate-track">
            <div className="ins-rate-fill" style={{ width: `${Math.max(0, Math.min(100, r.value))}%`, background: r.color }} />
          </div>
        </div>
      ))}
    </div>
  );
}
