import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as catalogs from '@/lib/catalogs';
import Services from './Services';

describe('Services', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('lists services', async () => {
    vi.spyOn(catalogs, 'listCatalogs').mockResolvedValue([
      { id: 1, title: 'Haircut', price: 50, description: 'A trim' },
      { id: 2, title: 'Shave', price: 30 },
    ]);
    render(<MemoryRouter><Services /></MemoryRouter>);
    expect(await screen.findByText('Haircut')).toBeInTheDocument();
    expect(screen.getByText('Shave')).toBeInTheDocument();
    expect(screen.getByText('AED 50.00')).toBeInTheDocument();
  });

  it('shows an empty state with no services', async () => {
    vi.spyOn(catalogs, 'listCatalogs').mockResolvedValue([]);
    render(<MemoryRouter><Services /></MemoryRouter>);
    expect(await screen.findByText(/no services yet/i)).toBeInTheDocument();
  });
});
