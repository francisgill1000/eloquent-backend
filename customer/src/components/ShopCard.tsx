import type { Shop } from '@/types';
import { Icons } from './Icons';

export function ShopCard({ shop, onOpen, onFavourite }: {
  shop: Shop;
  onOpen: (id: number) => void;
  onFavourite: (id: number) => void;
}) {
  const distance = shop.distance
    ?? (shop.distance_km != null
      ? `${typeof shop.distance_km === 'number' ? shop.distance_km.toFixed(1) : shop.distance_km} km`
      : null);

  return (
    <button type="button" className="c-shop-card" onClick={() => onOpen(shop.id)}>
      {shop.logo
        ? <img className="thumb" src={shop.logo} alt="" />
        : <span className="thumb" />}
      <div className="body">
        <div className="top">
          <span className={`c-open ${shop.is_open ? '' : 'closed'}`}>{shop.is_open ? 'Open' : 'Closed'}</span>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            <span
              role="button"
              aria-label="Toggle favourite"
              className={`c-fav ${shop.is_favourite ? 'on' : ''}`}
              onClick={(e) => { e.stopPropagation(); onFavourite(shop.id); }}
            >
              {shop.is_favourite ? <Icons.HeartFilled size={20} /> : <Icons.Heart size={20} />}
            </span>
            {shop.rating != null && <span style={{ fontSize: 13, fontWeight: 700 }}>{shop.rating}</span>}
          </span>
        </div>
        <span className="c-shop-name">{shop.name}</span>
        {shop.shop_code && <span className="c-code-pill">{shop.shop_code}</span>}
        <span className="c-shop-meta">
          {shop.location && <span>{shop.location}</span>}
          {distance && <span>{distance}</span>}
        </span>
        <span className="c-shop-foot">
          <span className="c-hours">
            {shop.today_working_hours?.start_time} - {shop.today_working_hours?.end_time}
          </span>
          <span className="c-btn" onClick={(e) => { e.stopPropagation(); onOpen(shop.id); }}>Book Now</span>
        </span>
      </div>
    </button>
  );
}
