import { useState } from 'react';
import api from '@/lib/api';
import { useCustomer } from '@/context/CustomerContext';

type Props = {
  mode: 'login' | 'register';
  name?: string;
  phone?: string;
  onDone: (name: string) => void;
};

/**
 * Inline auth under an assistant message. Name/phone are prefilled from what the
 * AI collected (editable); the password is typed here and sent ONLY to the real
 * /login /register endpoints — it never goes through the AI turn.
 */
export function AuthInline({ mode, name: initialName = '', phone: initialPhone = '', onDone }: Props) {
  const { loginCustomer } = useCustomer();
  const [name, setName] = useState(initialName);
  const [phone, setPhone] = useState(initialPhone);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [done, setDone] = useState(false);

  const submit = async () => {
    if (mode === 'register' && !name.trim()) { setError('Name is required.'); return; }
    if (!phone.trim()) { setError('Mobile number is required.'); return; }
    if (!password) { setError('Password is required.'); return; }
    if (mode === 'register' && password !== confirm) { setError('Passwords do not match.'); return; }

    setLoading(true);
    setError('');
    try {
      const payload = mode === 'register'
        ? { name: name.trim(), phone: phone.trim(), password, password_confirmation: confirm }
        : { phone: phone.trim(), password };
      const res = await api.post(mode === 'register' ? '/register' : '/login', payload);
      if (res.data?.token && res.data?.user) {
        loginCustomer(res.data.user, res.data.token);
        setDone(true);
        onDone(res.data.user.name ?? name.trim());
      } else {
        setError('Unexpected response — please try again.');
      }
    } catch (e: unknown) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
      setError(first || data?.message || (mode === 'register' ? 'Registration failed.' : 'Login failed.'));
    } finally {
      setLoading(false);
    }
  };

  if (done) return null;

  return (
    <div className="c-ai-auth">
      {mode === 'register' && (
        <input className="c-ai-auth-input" placeholder="Full name" value={name} onChange={(e) => setName(e.target.value)} />
      )}
      <input className="c-ai-auth-input" placeholder="Mobile number" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
      <input className="c-ai-auth-input" type="password" placeholder="Password" autoFocus value={password} onChange={(e) => setPassword(e.target.value)} />
      {mode === 'register' && (
        <input className="c-ai-auth-input" type="password" placeholder="Confirm password" value={confirm} onChange={(e) => setConfirm(e.target.value)} />
      )}
      {error && <div className="c-ai-auth-error">{error}</div>}
      <button className="c-ai-auth-submit" disabled={loading} onClick={submit}>
        {mode === 'register' ? 'Create account' : 'Sign in'}
      </button>
    </div>
  );
}
