import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import type { Shop } from '@/types';
import { AppBar } from '@/layout/AppBar';
import { ShopCard } from '@/components/ShopCard';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';

function getPosition(): Promise<GeolocationPosition> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) { reject(new Error('unsupported')); return; }
    navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 });
  });
}

export default function NearMe() {
  const navigate = useNavigate();
  const [shops, setShops] = useState<Shop[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchNearby = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const pos = await getPosition();
      const res = await api.get('/shops/nearby', { params: { lat: pos.coords.latitude, lon: pos.coords.longitude, radius: 10 } });
      setShops(res.data?.data ?? res.data ?? []);
    } catch {
      setError('Location permission denied or unavailable. Please enable location and try again.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void fetchNearby(); }, [fetchNearby]);

  return (
    <div className="m-screen">
      <AppBar title="Near Me" actions={
        <button className="c-wa" onClick={() => void fetchNearby()} aria-label="Refresh location"><Icons.Locate size={18} /></button>
      } />
      <div className="m-scroll">
        {loading && <Spinner label="Finding businesses near you…" />}
        {!loading && error && (
          <EmptyState
            icon={<Icons.MapPin size={32} />}
            title="Location Unavailable"
            subtitle={error}
            action={<button className="c-btn-ghost" onClick={() => void fetchNearby()}>Try again</button>}
          />
        )}
        {!loading && !error && shops.length === 0 && (
          <EmptyState icon={<Icons.Store size={32} />} title="No businesses found nearby" subtitle="Try again later." />
        )}
        {!loading && !error && shops.map((s) => (
          <ShopCard key={s.id} shop={s} onOpen={(id) => navigate(`/shop/${id}`)} onFavourite={() => {}} />
        ))}
      </div>
    </div>
  );
}
