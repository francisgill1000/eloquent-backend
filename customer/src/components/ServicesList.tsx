import type { Service } from '@/types';
import { groupByParentCategory } from '@/lib/services';

const money = (p: Service['price']) => `AED ${parseFloat(String(p ?? 0)).toFixed(2)}`;

/**
 * A compact, read-only list of a shop's services grouped by category — name on
 * the left, price on the right. Used both in the in-chat services sheet and
 * inline when the assistant emits the [[services]] marker. Renders nothing when
 * there are no services.
 */
export function ServicesList({ services }: { services: Service[] }) {
  if (!services?.length) return null;

  return (
    <div className="c-svc-list">
      {groupByParentCategory(services).map((group) => (
        <div className="c-svc-group" key={group.name}>
          <div className="c-svc-group-name">{group.name}</div>
          {group.items.map((s) => (
            <div className="c-svc-row" key={s.id}>
              <span className="c-svc-name">{s.title || s.name}</span>
              <span className="c-svc-price">{money(s.price)}</span>
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}
