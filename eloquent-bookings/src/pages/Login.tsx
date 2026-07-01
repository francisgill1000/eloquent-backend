import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import api from '@/lib/api';
import { useCustomer } from '@/context/CustomerContext';
import { Icons } from '@/components/Icons';

export default function Login() {
  const navigate = useNavigate();
  const { loginCustomer } = useCustomer();
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleLogin = async () => {
    if (!phone.trim()) { setError('Please enter your mobile number.'); return; }
    if (!password) { setError('Please enter your password.'); return; }
    setLoading(true);
    setError('');
    try {
      const res = await api.post('/login', { phone: phone.trim(), password });
      if (res.data?.token && res.data?.user) {
        loginCustomer(res.data.user, res.data.token);
        navigate('/account');
      } else {
        setError('Invalid response from server.');
      }
    } catch (e: unknown) {
      const data = (e as { response?: { data?: { message?: string; errors?: { phone?: string[] } } } })?.response?.data;
      setError(data?.message || data?.errors?.phone?.[0] || 'Login failed. Check your credentials.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-auth">
        <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={18} /> Back</button>
        <h1 className="c-auth-title">Welcome Back</h1>
        <p className="c-auth-sub">Sign in to view your bookings and favourites.</p>

        {error && <div className="c-error-box">{error}</div>}

        <div className="c-field-label">Mobile Number</div>
        <div className="c-input-row">
          <input type="tel" placeholder="e.g. 0501234567" value={phone} onChange={(e) => { setPhone(e.target.value); setError(''); }} />
        </div>

        <div className="c-field-label">Password</div>
        <div className="c-input-row">
          <input type={showPassword ? 'text' : 'password'} placeholder="Your password" value={password} onChange={(e) => { setPassword(e.target.value); setError(''); }} />
          <button type="button" onClick={() => setShowPassword((v) => !v)} style={{ background: 'none', border: 'none', color: 'var(--text-3)', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', cursor: 'pointer' }}>
            {showPassword ? 'Hide' : 'Show'}
          </button>
        </div>

        <button className="c-btn c-btn-block" disabled={loading} onClick={() => void handleLogin()}>
          {loading ? 'Signing in…' : 'Sign In'}
        </button>

        <p className="c-muted-center">Don't have an account? <Link className="c-link" to="/register">Register</Link></p>
      </div>
    </div></div>
  );
}
