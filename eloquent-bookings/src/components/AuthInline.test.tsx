import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import api from '@/lib/api';
import { AuthInline } from './AuthInline';

const loginCustomer = vi.fn();
vi.mock('@/context/CustomerContext', () => ({ useCustomer: () => ({ loginCustomer }) }));
vi.mock('@/lib/api', () => ({ default: { post: vi.fn() } }));

describe('AuthInline', () => {
  beforeEach(() => vi.clearAllMocks());

  it('logs in with the prefilled phone + typed password and reports done', async () => {
    (api.post as any).mockResolvedValue({ data: { token: 'tok', user: { id: 1, name: 'Aisha' } } });
    const onDone = vi.fn();
    render(<AuthInline mode="login" phone="0501234567" onDone={onDone} />);

    await userEvent.type(screen.getByPlaceholderText(/password/i), 'secret');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/login', { phone: '0501234567', password: 'secret' }));
    expect(loginCustomer).toHaveBeenCalledWith({ id: 1, name: 'Aisha' }, 'tok');
    expect(onDone).toHaveBeenCalledWith('Aisha');
  });

  it('registers with name, phone, password + confirmation', async () => {
    (api.post as any).mockResolvedValue({ data: { token: 'tok', user: { id: 2, name: 'Sam' } } });
    const onDone = vi.fn();
    render(<AuthInline mode="register" name="Sam" phone="0509999999" onDone={onDone} />);

    await userEvent.type(screen.getByPlaceholderText(/^password/i), 'secret');
    await userEvent.type(screen.getByPlaceholderText(/confirm/i), 'secret');
    await userEvent.click(screen.getByRole('button', { name: /create account/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/register', {
      name: 'Sam', phone: '0509999999', password: 'secret', password_confirmation: 'secret',
    }));
    expect(onDone).toHaveBeenCalledWith('Sam');
  });

  it('shows an error and does not call onDone on failure', async () => {
    (api.post as any).mockRejectedValue({ response: { data: { message: 'Invalid credentials provided.' } } });
    const onDone = vi.fn();
    render(<AuthInline mode="login" phone="0501234567" onDone={onDone} />);

    await userEvent.type(screen.getByPlaceholderText(/password/i), 'wrong');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));

    expect(await screen.findByText('Invalid credentials provided.')).toBeInTheDocument();
    expect(onDone).not.toHaveBeenCalled();
  });
});
