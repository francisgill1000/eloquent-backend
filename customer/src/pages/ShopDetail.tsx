import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '@/lib/api';
import { toggleFavourite } from '@/lib/shops';
import { buildBookingPayload } from '@/lib/booking';
import { generateDates, formatLocalDate, dow } from '@/lib/date';
import { groupByParentCategory } from '@/lib/services';
import type { Shop } from '@/types';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { ThemeToggle } from '@/components/ThemeToggle';

export default function ShopDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const dates = useMemo(() => generateDates(31), []);
  const [shop, setShop] = useState<Shop | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedDate, setSelectedDate] = useState<Date>(dates[0]);
  const [selectedTime, setSelectedTime] = useState('');
  const [selectedServices, setSelectedServices] = useState<number[]>([]);
  const [booking, setBooking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchShop = async () => {
    try {
      const res = await api.get(`/shops/${id}`, { params: { date: formatLocalDate(selectedDate) } });
      const data: Shop = res.data?.data ?? res.data;
      if (data && !Array.isArray(data.catalogs)) data.catalogs = [];
      setShop(data);
    } catch { /* not found handled below */ }
    finally { setLoading(false); }
  };

  useEffect(() => { void fetchShop(); /* eslint-disable-next-line */ }, [id, selectedDate]);

  const total = useMemo(() => {
    if (!shop?.catalogs) return 0;
    return selectedServices.reduce((sum, sid) => {
      const s = shop.catalogs!.find((c) => c.id === sid);
      return sum + (s?.price != null ? parseFloat(String(s.price)) : 0);
    }, 0);
  }, [shop, selectedServices]);

  const toggleService = (sid: number) =>
    setSelectedServices((prev) => (prev.includes(sid) ? prev.filter((x) => x !== sid) : [...prev, sid]));

  // The shop API exposes time slots for the chosen date; fall back to none.
  const slots: string[] = ((shop as unknown as { slots?: string[] })?.slots) ?? [];

  const handleBook = async () => {
    if (!shop || booking || !selectedTime) return;
    setBooking(true);
    setError(null);
    try {
      const payload = buildBookingPayload(formatLocalDate(selectedDate), selectedTime, shop.catalogs ?? [], selectedServices);
      const res = await api.post(`/shops/${shop.id}/book`, payload);
      const bookingId = res.data?.data?.id ?? res.data?.id;

      // Pay-first: the booking exists but is unpaid. Send the customer to Ziina;
      // it's confirmed once payment settles. If we can't start payment, the
      // booking still stands (eloquent-bookings) — land on its page.
      try {
        const pay = await api.post(`/booking/${bookingId}/invoice/pay`);
        const url = pay.data?.data?.redirect_url;
        if (url) {
          window.location.href = url;
          return;
        }
      } catch { /* fall through to the booking page */ }

      navigate(`/booking/${bookingId}`);
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg ?? 'Something went wrong. Please try again.');
    } finally {
      setBooking(false);
    }
  };

  if (loading) return <div className="m-screen"><Spinner /></div>;
  if (!shop) return (
    <div className="m-screen">
      <div className="m-appbar"><button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={18} /> Back</button><ThemeToggle /></div>
      <div className="m-scroll"><p style={{ textAlign: 'center', color: 'var(--text-3)' }}>Business not found.</p></div>
    </div>
  );

  return (
    <div className="m-screen">
      <div className="m-appbar">
        <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={18} /> Back</button>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <ThemeToggle />
          <span className="c-fav on" role="button" aria-label="Toggle favourite" onClick={() => void toggleFavourite(shop.id)}>
            <Icons.HeartFilled size={22} />
          </span>
        </div>
      </div>

      <div className="m-scroll">
        {shop.hero_image || shop.logo
          ? <img src={shop.hero_image || shop.logo} alt="" style={{ width: 'calc(100% - 32px)', height: 180, objectFit: 'cover', borderRadius: 'var(--r-lg)', margin: '0 16px 12px' }} />
          : null}
        <h2 style={{ margin: '0 16px', fontSize: 22 }}>{shop.name}</h2>
        {shop.location && <p style={{ margin: '4px 16px 16px', color: 'var(--text-3)', fontSize: 13 }}>{shop.location}</p>}

        <div className="c-chat-row">
          <button
            className="c-chat-btn"
            onClick={() => navigate(`/shop/${shop.id}/chat`, { state: { shopName: shop.name, shopLogo: shop.logo } })}
          >
            <Icons.Chat size={17} /> Live Chat
          </button>
          {shop.phone && (
            <a
              className="c-chat-btn wa"
              href={`https://wa.me/${String(shop.phone).replace(/\D+/g, '')}`}
              target="_blank"
              rel="noopener noreferrer"
            >
              <Icons.WhatsApp size={17} /> WhatsApp
            </a>
          )}
        </div>

        <div className="m-section-title" style={{ padding: '0 16px' }}><h3>Select date</h3></div>
        <div className="c-date-strip">
          {dates.map((d) => {
            const active = formatLocalDate(d) === formatLocalDate(selectedDate);
            return (
              <button key={formatLocalDate(d)} className={`c-date-cell ${active ? 'active' : ''}`} onClick={() => { setSelectedDate(d); setSelectedTime(''); }}>
                <div className="dow">{dow(d)}</div>
                <div className="dnum">{d.getDate()}</div>
              </button>
            );
          })}
        </div>

        {slots.length > 0 && (
          <>
            <div className="m-section-title" style={{ padding: '0 16px' }}><h3>Select time</h3></div>
            <div className="c-slot-grid">
              {slots.map((t) => (
                <button key={t} className={`c-slot ${selectedTime === t ? 'active' : ''}`} onClick={() => setSelectedTime(t)}>{t}</button>
              ))}
            </div>
          </>
        )}

        {(shop.catalogs?.length ?? 0) > 0 && (
          <>
            <div className="m-section-title" style={{ padding: '16px 16px 0', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h3>Services</h3>
              {selectedServices.length > 0 && <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--mint-300)' }}>{selectedServices.length} selected</span>}
            </div>
            {groupByParentCategory(shop.catalogs!).map((group) => (
              <div className="c-cat-card" key={group.name}>
                {group.image ? (
                  <div className="c-cat-head">
                    <img src={group.image} alt={group.name} />
                    <span className="c-cat-name">{group.name}</span>
                  </div>
                ) : (
                  <div className="c-cat-head-plain"><span className="c-cat-name">{group.name}</span></div>
                )}
                <div className="c-cat-items">
                  {group.items.map((s) => {
                    const on = selectedServices.includes(s.id);
                    return (
                      <div key={s.id} className={`c-cat-item ${on ? 'on' : ''}`} onClick={() => toggleService(s.id)}>
                        {s.image && (
                          <div className="thumb"><img src={s.image} alt="" /></div>
                        )}
                        <div className="info">
                          <h4>{s.title || s.name}</h4>
                          {s.description && <p>{s.description}</p>}
                          <span className="price">AED {parseFloat(String(s.price ?? 0)).toFixed(2)}</span>
                        </div>
                        <button className={`add ${on ? 'on' : ''}`} aria-label={on ? 'Remove service' : 'Add service'} onClick={(e) => { e.stopPropagation(); toggleService(s.id); }}>
                          {on ? <Icons.Check size={20} /> : <Icons.Plus size={20} />}
                        </button>
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
          </>
        )}

        {error && <p className="c-error-box" style={{ margin: '0 16px 12px' }}>{error}</p>}
      </div>

      <div className="c-book-bar">
        <span className="total">AED {total.toFixed(2)}</span>
        <button className="c-btn c-btn-block" style={{ flex: 1 }} disabled={booking || !selectedTime} onClick={() => void handleBook()}>
          {booking ? 'Starting payment…' : 'Pay & Confirm'}
        </button>
      </div>
    </div>
  );
}
