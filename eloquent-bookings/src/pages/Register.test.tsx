import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { CustomerProvider } from '@/context/CustomerContext';
import Register from './Register';

vi.mock('@/lib/api', () => ({ default: { post: vi.fn() } }));
import api from '@/lib/api';

const renderReg = () => render(
  <MemoryRouter><CustomerProvider><Register /></CustomerProvider></MemoryRouter>,
);

beforeEach(() => vi.clearAllMocks());

describe('Register', () => {
  it('errors when passwords do not match', async () => {
    renderReg();
    await userEvent.type(screen.getByPlaceholderText('John Smith'), 'Ada');
    await userEvent.type(screen.getByPlaceholderText('e.g. 0501234567'), '050');
    await userEvent.type(screen.getByPlaceholderText('Min 5 characters'), 'secret');
    await userEvent.type(screen.getByPlaceholderText('Repeat password'), 'other1');
    await userEvent.click(screen.getByRole('button', { name: /create account/i }));
    expect(await screen.findByText(/passwords do not match/i)).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
  });

  it('submits the full form on success', async () => {
    (api.post as any).mockResolvedValue({ data: { token: 't', user: { id: 1, name: 'Ada', phone: '050' } } });
    renderReg();
    await userEvent.type(screen.getByPlaceholderText('John Smith'), 'Ada');
    await userEvent.type(screen.getByPlaceholderText('e.g. 0501234567'), '050');
    await userEvent.type(screen.getByPlaceholderText('Min 5 characters'), 'secret');
    await userEvent.type(screen.getByPlaceholderText('Repeat password'), 'secret');
    await userEvent.click(screen.getByRole('button', { name: /create account/i }));
    expect(api.post).toHaveBeenCalledWith('/register', { name: 'Ada', phone: '050', password: 'secret', password_confirmation: 'secret' });
  });
});
