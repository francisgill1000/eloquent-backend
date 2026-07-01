import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import api from '@/lib/api';
import { useCustomer } from '@/context/CustomerContext';
import { Icons } from '@/components/Icons';

type Form = { name: string; phone: string; password: string; password_confirmation: string };

export default function Register() {
  const navigate = useNavigate();
  const { loginCustomer } = useCustomer();
  const [form, setForm] = useState<Form>({ name: '', phone: '', password: '', password_confirmation: '' });
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const set = (key: keyof Form, value: string) => { setForm((f) => ({ ...f, [key]: value })); setError(''); };

  const handleRegister = async () => {
    if (!form.name.trim()) { setError('Name is required.'); return; }
    if (!form.phone.trim()) { setError('Mobile number is required.'); return; }
    if (!form.password) { setError('Password is required.'); return; }
    if (form.password.length < 5) { setError('Password must be at least 5 characters.'); return; }
    if (form.password !== form.password_confirmation) { setError('Passwords do not match.'); return; }
    setLoading(true);
    setError('');
    try {
      const res = await api.post('/register', form);
      if (res.data?.token && res.data?.user) {
        loginCustomer(res.data.user, res.data.token);
        navigate('/account');
      } else {
        setError('Registration failed. Please try again.');
      }
    } catch (e: unknown) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
      setError(firstError || data?.message || 'Registration failed.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-auth">
        <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={18} /> Back to Login</button>
        <h1 className="c-auth-title">Create Account</h1>
        <p className="c-auth-sub">Sign up to track your bookings and manage favourites.</p>

        {error && <div className="c-error-box">{error}</div>}

        <div className="c-field-label">Full Name</div>
        <div className="c-input-row"><input placeholder="John Smith" value={form.name} onChange={(e) => set('name', e.target.value)} /></div>

        <div className="c-field-label">Mobile Number</div>
        <div className="c-input-row"><input type="tel" placeholder="e.g. 0501234567" value={form.phone} onChange={(e) => set('phone', e.target.value)} /></div>

        <div className="c-field-label">Password</div>
        <div className="c-input-row">
          <input type={showPassword ? 'text' : 'password'} placeholder="Min 5 characters" value={form.password} onChange={(e) => set('password', e.target.value)} />
          <button type="button" onClick={() => setShowPassword((v) => !v)} style={{ background: 'none', border: 'none', color: 'var(--text-3)', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', cursor: 'pointer' }}>
            {showPassword ? 'Hide' : 'Show'}
          </button>
        </div>

        <div className="c-field-label">Confirm Password</div>
        <div className="c-input-row"><input type={showPassword ? 'text' : 'password'} placeholder="Repeat password" value={form.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} /></div>

        <button className="c-btn c-btn-block" disabled={loading} onClick={() => void handleRegister()}>
          {loading ? 'Creating…' : 'Create Account'}
        </button>

        <p className="c-muted-center">Already have an account? <Link className="c-link" to="/login">Sign In</Link></p>
      </div>
    </div></div>
  );
}
