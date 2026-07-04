import type { MasterShop } from '@/types';
import { Icons } from './Icons';
import { shortDate } from '@/lib/format';

function initial(name: string): string {
  const c = (name || '?').trim().charAt(0);
  return c ? c.toUpperCase() : '?';
}

export function MasterShopCard({ shop, onOpen }: {
  shop: MasterShop;
  onOpen: (id: number) => void;
}) {
  const inactive = shop.status !== 'active';
  return (
    <button type="button" className="c-msc" onClick={() => onOpen(shop.id)}>
      <span className="c-msc-thumb" aria-hidden>{initial(shop.name)}</span>
      <div className="c-msc-body">
        <div className="c-msc-top">
          <span className={`c-msc-wa${shop.wa_connected ? ' on' : ''}`}>
            <Icons.WhatsApp size={13} /> {shop.wa_connected ? 'Connected' : 'Not set up'}
          </span>
          <span className="c-msc-tags">
            {shop.is_master && <em className="c-msc-tag master">master</em>}
            {shop.subscription_status === 'trialing' && <em className="c-msc-tag master">trialing · {shop.days_left ?? 0}d</em>}
            {shop.subscription_status === 'active' && <em className="c-msc-tag master">active</em>}
            {shop.subscription_status === 'expired' && <em className="c-msc-tag off">expired</em>}
            {inactive && <em className="c-msc-tag off">Inactive</em>}
          </span>
        </div>
        <span className="c-msc-name">{shop.name}</span>
        <span className="c-msc-meta">
          {shop.category || 'No category'}{shop.phone ? ` · ${shop.phone}` : ''}
        </span>
        <span className="c-msc-foot">
          {shop.bookings_count ?? 0} bookings · Joined {shortDate(shop.created_at)}
        </span>
      </div>
    </button>
  );
}
