import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as catalogs from '@/lib/catalogs';
import ServiceEdit from './ServiceEdit';

describe('ServiceEdit (create)', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('creates a service from the form', async () => {
    const create = vi.spyOn(catalogs, 'createCatalog').mockResolvedValue({ id: 9, title: 'Facial' });
    render(
      <MemoryRouter initialEntries={['/services/new']}>
        <Routes><Route path="/services/new" element={<ServiceEdit />} /></Routes>
      </MemoryRouter>,
    );
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/title/i), 'Facial');
    await user.type(screen.getByLabelText(/description/i), 'Deep clean');
    await user.type(screen.getByLabelText(/price/i), '75');
    await user.click(screen.getByRole('button', { name: /create service/i }));
    expect(create).toHaveBeenCalledWith(expect.objectContaining({ title: 'Facial', description: 'Deep clean', price: '75' }));
  });

  it('blocks save without a title', async () => {
    const create = vi.spyOn(catalogs, 'createCatalog').mockResolvedValue({ id: 1 });
    render(
      <MemoryRouter initialEntries={['/services/new']}>
        <Routes><Route path="/services/new" element={<ServiceEdit />} /></Routes>
      </MemoryRouter>,
    );
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /create service/i }));
    expect(create).not.toHaveBeenCalled();
    expect(screen.getByText(/enter a service title/i)).toBeInTheDocument();
  });
});
