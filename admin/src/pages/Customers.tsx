import { useEffect, useState, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { listCustomers, type CustomerListItem } from '@/lib/customers';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function fmtDate(s: string | null): string {
  if (!s) return '—';
  const d = new Date(String(s).slice(0, 10));
  if (isNaN(d.getTime())) return '—';
  return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}
const fmtMoney = (n: number | string) => `AED ${Number(n || 0).toLocaleString()}`;

export default function Customers() {
  const navigate = useNavigate();
  const { shop } = useShop();

  const [items, setItems] = useState<CustomerListItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);

  const fetchPage = useCallback(async (p: number, term: string, append: boolean) => {
    if (!shop?.id) return;
    append ? setLoadingMore(true) : setLoading(true);
    setError('');
    try {
      const res = await listCustomers(shop.id, { page: p, search: term || undefined, per_page: 20 });
      setItems((prev) => (append ? [...prev, ...res.data] : res.data));
      setTotal(res.total);
      setPage(res.current_page);
      setLastPage(res.last_page);
    } catch {
      setError('Could not load customers.');
    } finally {
      append ? setLoadingMore(false) : setLoading(false);
    }
  }, [shop?.id]);

  // Initial load + debounced search.
  useEffect(() => {
    if (debounce.current) clearTimeout(debounce.current);
    debounce.current = setTimeout(() => { void fetchPage(1, search, false); }, 250);
    return () => { if (debounce.current) clearTimeout(debounce.current); };
  }, [search, fetchPage]);

  return (
    <div className="m-screen c-customers"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Customers</h1>
        <p className="c-page-sub">Everyone who has booked with you — one entry per contact number.</p>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      <div className="c-input-row" style={{ marginBottom: 12 }}>
        <Icons.Search size={15} />
        <input type="search" placeholder="Search by name or number" value={search}
          onChange={(e) => setSearch(e.target.value)} style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', font: 'inherit' }} />
      </div>

      {loading ? (
        <Spinner label="Loading customers…" />
      ) : items.length === 0 ? (
        <EmptyState title={search ? 'No matches' : 'No customers yet'}
          subtitle={search ? 'Try a different name or number.' : 'Customers appear here after their first booking.'} />
      ) : (
        <>
          <div className="c-listhead">
            <span className="c-dt-sub">{total.toLocaleString()} customer{total !== 1 ? 's' : ''}</span>
          </div>

          <div className="c-dtable-wrap">
            <table className="c-dtable">
              <thead>
                <tr>
                  <th>Customer</th>
                  <th className="ta-r" style={{ width: 90 }}>Bookings</th>
                  <th style={{ width: 130 }}>Last visit</th>
                  <th className="ta-r" style={{ width: 120 }}>Spent</th>
                </tr>
              </thead>
              <tbody>
                {items.map((c) => (
                  <tr key={c.id} className="c-dt-click" onClick={() => navigate(`/customers/${c.id}`)}>
                    <td className="c-dt-namecell">
                      <span className="c-dt-name">{c.name || 'Guest'}</span>
                      <span className="c-dt-sub">{c.whatsapp || '—'}</span>
                    </td>
                    <td className="ta-r">{c.bookings_count}</td>
                    <td>{fmtDate(c.last_visit_date)}</td>
                    <td className="ta-r">{fmtMoney(c.total_spent)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {page < lastPage && (
            <button className="c-btn c-btn-block" style={{ marginTop: 12 }} disabled={loadingMore}
              onClick={() => void fetchPage(page + 1, search, true)}>
              {loadingMore ? 'Loading…' : 'Load more'}
            </button>
          )}
        </>
      )}
    </div></div>
  );
}
