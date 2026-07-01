import { useNavigate } from 'react-router-dom';
import { useCustomer } from '@/context/CustomerContext';
import { AppBar } from '@/layout/AppBar';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { Icons } from '@/components/Icons';

function initials(name?: string): string {
  if (!name) return '?';
  return name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();
}

export default function Account() {
  const navigate = useNavigate();
  const { customer, logoutCustomer } = useCustomer();

  if (!customer) {
    return (
      <div className="m-screen">
        <AppBar title="Account" actions={<WhatsAppButton />} />
        <div className="m-scroll">
          <div style={{ textAlign: 'center', padding: '48px 24px' }}>
            <div style={{ color: 'var(--text-4)', marginBottom: 12, display: 'grid', placeItems: 'center' }}><Icons.User size={56} /></div>
            <h2 style={{ margin: '0 0 8px' }}>Sign In</h2>
            <p style={{ color: 'var(--text-3)', marginBottom: 24 }}>Log in to track your bookings and save favourites.</p>
            <button className="c-btn c-btn-block" style={{ marginBottom: 12 }} onClick={() => navigate('/login')}>Sign In</button>
            <button className="c-btn-ghost" style={{ width: '100%' }} onClick={() => navigate('/register')}>Create Account</button>
          </div>
        </div>
      </div>
    );
  }

  const handleLogout = () => {
    if (confirm('Are you sure you want to sign out?')) logoutCustomer();
  };

  const links: { label: string; to: string }[] = [
    { label: 'My Bookings', to: '/bookings' },
    { label: 'Favourites', to: '/favourites' },
    { label: 'Explore', to: '/explore' },
  ];

  return (
    <div className="m-screen">
      <AppBar title="My Account" actions={<WhatsAppButton />} />
      <div className="m-scroll">
        <div className="c-avatar">{initials(customer.name)}</div>
        <h2 style={{ textAlign: 'center', margin: 0 }}>{customer.name}</h2>
        {customer.email && <p style={{ textAlign: 'center', color: 'var(--text-3)', marginTop: 4 }}>{customer.email}</p>}

        <div className="c-card" style={{ padding: 0, marginTop: 20 }}>
          {links.map((l, i) => (
            <button
              key={l.to}
              onClick={() => navigate(l.to)}
              style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%', padding: 16, background: 'none', border: 'none', borderBottom: i < links.length - 1 ? '1px solid var(--border-1)' : 'none', color: 'var(--text-1)', cursor: 'pointer', fontSize: 15, fontWeight: 600 }}
            >
              {l.label}
              <Icons.Chevron size={18} />
            </button>
          ))}
        </div>

        <button
          onClick={handleLogout}
          style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10, width: 'calc(100% - 32px)', margin: '20px 16px', height: 52, borderRadius: 'var(--r-lg)', border: '1px solid rgba(248,113,113,0.3)', background: 'var(--danger-soft)', color: 'var(--danger)', fontWeight: 700, cursor: 'pointer' }}
        >
          <Icons.Logout size={18} /> Sign Out
        </button>
      </div>
    </div>
  );
}
