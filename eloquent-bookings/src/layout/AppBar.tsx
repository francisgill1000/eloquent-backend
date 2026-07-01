import type { ReactNode } from 'react';

export function AppBar({ title, sub, actions }: { title: string; sub?: string; actions?: ReactNode }) {
  return (
    <div className="m-appbar">
      <div>
        <h1>{title}</h1>
        {sub && <div className="sub">{sub}</div>}
      </div>
      {actions && <div className="m-appbar-actions">{actions}</div>}
    </div>
  );
}
