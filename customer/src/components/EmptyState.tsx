import type { ReactNode } from 'react';

export function EmptyState({ icon, title, subtitle, action }: { icon?: ReactNode; title: string; subtitle?: string; action?: ReactNode }) {
  return (
    <div className="m-empty">
      {icon}
      <p style={{ fontWeight: 700, color: 'var(--text-1)', margin: '8px 0 0' }}>{title}</p>
      {subtitle && <p style={{ fontSize: 13, color: 'var(--text-3)', margin: '4px 0 0' }}>{subtitle}</p>}
      {action && <div style={{ marginTop: 16 }}>{action}</div>}
    </div>
  );
}
