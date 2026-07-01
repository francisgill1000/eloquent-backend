import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Home from './Home';

vi.mock('@/lib/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
import api from '@/lib/api';

beforeEach(() => {
  vi.clearAllMocks();
  (api.get as any).mockResolvedValue({
    data: { data: [{ id: 1, name: 'Acme Spa', is_open: true }], current_page: 1, last_page: 1 },
  });
});

describe('Home', () => {
  it('fetches and renders shops', async () => {
    render(<MemoryRouter><Home /></MemoryRouter>);
    await waitFor(() => expect(screen.getByText('Acme Spa')).toBeInTheDocument());
    expect(api.get).toHaveBeenCalledWith('/shops', expect.objectContaining({ params: expect.objectContaining({ page: 1, per_page: 10 }) }));
  });
});
