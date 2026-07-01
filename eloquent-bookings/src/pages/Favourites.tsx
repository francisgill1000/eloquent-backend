import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import { toggleFavourite } from '@/lib/shops';
import type { Shop, Paginated } from '@/types';
import { AppBar } from '@/layout/AppBar';
import { ShopCard } from '@/components/ShopCard';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';

export default function Favourites() {
  const navigate = useNavigate();
  const [shops, setShops] = useState<Shop[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchFavourites = async () => {
    setLoading(true);
    try {
      const res = await api.get<Paginated<Shop>>('/shops', { params: { favourites: 1, per_page: 50 } });
      setShops(res.data.data ?? []);
    } catch { /* empty state */ }
    finally { setLoading(false); }
  };

  useEffect(() => { void fetchFavourites(); }, []);

  const onFavourite = async (id: number) => {
    setShops((prev) => prev.filter((s) => s.id !== id));
    try { await toggleFavourite(id); } catch { void fetchFavourites(); }
  };

  return (
    <div className="m-screen">
      <AppBar title="Favourites" />
      <div className="m-scroll">
        {loading && <Spinner />}
        {!loading && shops.length === 0 && (
          <EmptyState
            icon={<Icons.Heart size={32} />}
            title="No favourites yet"
            subtitle="Tap the heart on a business to save it here."
            action={<button className="c-btn-ghost" onClick={() => navigate('/explore')}>Explore</button>}
          />
        )}
        {!loading && shops.map((s) => (
          <ShopCard key={s.id} shop={{ ...s, is_favourite: true }} onOpen={(id) => navigate(`/shop/${id}`)} onFavourite={onFavourite} />
        ))}
      </div>
    </div>
  );
}
