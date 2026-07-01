import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import { toggleFavourite } from '@/lib/shops';
import type { Shop, Paginated } from '@/types';
import { AppBar } from '@/layout/AppBar';
import { ShopCard } from '@/components/ShopCard';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { ThemeToggle } from '@/components/ThemeToggle';
import { Icons } from '@/components/Icons';

export default function Home() {
  const navigate = useNavigate();
  const [shops, setShops] = useState<Shop[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const debounce = useRef<ReturnType<typeof setTimeout>>();

  const fetchShops = async (p = 1, q = '') => {
    setLoading(true);
    try {
      const res = await api.get<Paginated<Shop>>('/shops', { params: { page: p, per_page: 10, search: q || undefined } });
      const data = res.data;
      setShops((prev) => (p === 1 ? data.data ?? [] : [...prev, ...(data.data ?? [])]));
      setPage(data.current_page ?? p);
      setLastPage(data.last_page ?? 1);
    } catch { /* surfaced via empty state */ }
    finally { setLoading(false); }
  };

  useEffect(() => { void fetchShops(1); }, []);

  useEffect(() => {
    clearTimeout(debounce.current);
    debounce.current = setTimeout(() => { void fetchShops(1, search); }, 500);
    return () => clearTimeout(debounce.current);
  }, [search]);

  const onFavourite = async (id: number) => {
    setShops((prev) => prev.map((s) => (s.id === id ? { ...s, is_favourite: !s.is_favourite } : s)));
    try { await toggleFavourite(id); } catch { void fetchShops(page, search); }
  };

  return (
    <div className="m-screen">
      <AppBar title="Eloquent Bookings" actions={<>
        <button className="c-wa" aria-label="Near me" onClick={() => navigate('/near-me')}><Icons.MapPin size={18} /></button>
        <ThemeToggle /><WhatsAppButton />
      </>} />
      <div className="c-search">
        <Icons.Search size={18} />
        <input placeholder="Search businesses…" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      <div className="m-scroll">
        {shops.map((s) => (
          <ShopCard key={s.id} shop={s} onOpen={(id) => navigate(`/shop/${id}`)} onFavourite={onFavourite} />
        ))}
        {loading && <Spinner />}
        {!loading && shops.length === 0 && (
          <EmptyState icon={<Icons.Search size={32} />} title="No results found" subtitle={search ? `Nothing matches "${search}".` : 'Try a different search.'} />
        )}
        {!loading && page < lastPage && (
          <button className="c-btn-ghost" style={{ display: 'block', margin: '8px auto 24px' }} onClick={() => fetchShops(page + 1, search)}>
            Load more
          </button>
        )}
      </div>
    </div>
  );
}
