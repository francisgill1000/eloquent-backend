import { useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { approveQrLogin } from '@/lib/shops';

type Status = 'idle' | 'approving' | 'success' | 'error';

export default function ScanApprove() {
  const { token } = useParams<{ token: string }>();
  const navigate = useNavigate();
  const { shop } = useShop();
  const [status, setStatus] = useState<Status>('idle');
  const [message, setMessage] = useState('');

  const approve = async () => {
    if (!token) return;
    setStatus('approving');
    setMessage('');
    try {
      await approveQrLogin(token);
      setStatus('success');
      setMessage('Desktop login approved! You can return to your computer.');
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setStatus('error');
      setMessage(d?.message || 'Approval failed. The code may have expired.');
    }
  };

  if (!shop) {
    return (
      <div className="m-screen"><div className="m-scroll">
        <div className="c-auth">
          <h1 className="c-auth-title">Approve Desktop Login</h1>
          <p className="c-auth-sub">Log in to your business account first, then approve this request.</p>
          <Link className="c-btn c-btn-block" to="/login">Log In</Link>
        </div>
      </div></div>
    );
  }

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-auth">
        <h1 className="c-auth-title">Approve Desktop Login</h1>
        <p className="c-auth-sub">Approve a login request for <strong>{shop.name}</strong> on another device.</p>

        {status === 'success' ? (
          <>
            <div className="c-card" style={{ color: 'var(--mint-300)', display: 'flex', gap: 8, alignItems: 'center' }}>
              <Icons.Check size={20} /> {message}
            </div>
            <button className="c-btn c-btn-block" onClick={() => navigate('/')}>Back to Dashboard</button>
          </>
        ) : status === 'error' ? (
          <>
            <div className="c-error-box">{message}</div>
            <button className="c-btn c-btn-block" onClick={() => void approve()}>Try Again</button>
          </>
        ) : (
          <button className="c-btn c-btn-block" disabled={status === 'approving'} onClick={() => void approve()}>
            {status === 'approving' ? 'Approving…' : 'Approve Login'}
          </button>
        )}
      </div>
    </div></div>
  );
}
