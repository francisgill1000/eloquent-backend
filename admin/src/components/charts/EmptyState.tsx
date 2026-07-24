import { Icons } from '@/components/Icons';

/** Shown in place of a chart when there is nothing to plot. */
export function EmptyState({ text }: { text: string }) {
  return (
    <div className="ins-empty">
      <span className="ins-empty-ic"><Icons.Chart size={26} /></span>
      <span className="ins-empty-txt">{text}</span>
    </div>
  );
}
