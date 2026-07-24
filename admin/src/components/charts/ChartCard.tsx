import type { ReactNode } from 'react';
import { Icons } from '@/components/Icons';

/** Card shell for a chart: icon, title, subtitle, body. */
export function ChartCard({ icon, title, sub, span2, children }: {
  icon: keyof typeof Icons; title: string; sub: string; span2?: boolean; children: ReactNode;
}) {
  const Icon = Icons[icon];
  return (
    <div className={`ins-card${span2 ? ' span2' : ''}`}>
      <div className="ins-card-head">
        <span className="ins-card-ic"><Icon size={17} /></span>
        <span className="ins-card-titles">
          <span className="ins-card-title">{title}</span>
          <span className="ins-card-sub">{sub}</span>
        </span>
      </div>
      {children}
    </div>
  );
}
