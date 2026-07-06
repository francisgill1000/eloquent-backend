import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ModuleGuard } from './ModuleGuard';
import { ShopContext } from '@/context/ShopContext';
import type { Shop } from '@/types';

function renderAt(modules: string[]) {
  const ctx = { shop: { modules } as unknown as Shop } as never;
  render(
    <ShopContext.Provider value={ctx}>
      <MemoryRouter initialEntries={['/leads']}>
        <Routes>
          <Route element={<ModuleGuard module="leads" />}>
            <Route path="/leads" element={<div>LEADS PAGE</div>} />
          </Route>
          <Route path="/" element={<div>HOME PAGE</div>} />
        </Routes>
      </MemoryRouter>
    </ShopContext.Provider>,
  );
}

describe('ModuleGuard', () => {
  it('renders the route when the module is enabled', () => {
    renderAt(['bookings', 'leads']);
    expect(screen.getByText('LEADS PAGE')).toBeTruthy();
  });
  it('redirects home when the module is absent', () => {
    renderAt(['bookings']);
    expect(screen.getByText('HOME PAGE')).toBeTruthy();
  });
});
