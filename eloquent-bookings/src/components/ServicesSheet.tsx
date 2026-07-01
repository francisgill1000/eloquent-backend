import type { Service } from '@/types';
import { Icons } from './Icons';
import { ServicesList } from './ServicesList';

/**
 * A dismissable bottom sheet listing the shop's services. Opened from the chat
 * header button. Read-only — see [ServicesList].
 */
export function ServicesSheet(
  { services, onClose }: { services: Service[]; onClose: () => void },
) {
  return (
    <div className="c-sheet-backdrop" onClick={onClose}>
      <div className="c-sheet" role="dialog" aria-label="Services" onClick={(e) => e.stopPropagation()}>
        <div className="c-sheet-head">
          <span className="c-sheet-title">Services</span>
          <button className="c-icon-btn" aria-label="Close" onClick={onClose}>
            <Icons.Close size={18} />
          </button>
        </div>
        <div className="c-sheet-body">
          {services.length ? <ServicesList services={services} /> : (
            <p className="c-thread-empty" style={{ marginTop: 8 }}>No services listed yet.</p>
          )}
        </div>
      </div>
    </div>
  );
}
