import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { CustomerProvider } from '@/context/CustomerContext';
import Login from './Login';

vi.mock('@/lib/api', () => ({ default: { post: vi.fn() } }));
import api from '@/lib/api';

const renderLogin = () => render(
  <MemoryRouter><CustomerProvider><Login /></CustomerProvider></MemoryRouter>,
);

beforeEach(() => vi.clearAllMocks());

describe('Login', () => {
  it('posts credentials and stores the token on success', async () => {
    (api.post as any).mockResolvedValue({ data: { token: 'tok', user: { id: 1, name: 'Ada', phone: '050' } } });
    renderLogin();
    await userEvent.type(screen.getByPlaceholderText('e.g. 0501234567'), '0501234567');
    await userEvent.type(screen.getByPlaceholderText('Your password'), 'secret');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/login', { phone: '0501234567', password: 'secret' }));
    expect(localStorage.getItem('customer_token')).toBe('tok');
  });

  it('shows a validation error when fields are empty', async () => {
    renderLogin();
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByText(/enter your mobile number/i)).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
  });
});
