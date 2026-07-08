import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import BookingPreview from './BookingPreview';

const booking = { customer_name: 'Sarah', customer_phone: '055 010 2030', service: 'Hair Cut', price: '150.00', date: '2026-07-09', start_time: '09:30', end_time: '10:00', staff_name: 'Aisha' };

describe('BookingPreview', () => {
  it('renders the passed booking without any API call', () => {
    render(<MemoryRouter initialEntries={[{ pathname: '/booking/preview', state: { booking } }]}><BookingPreview /></MemoryRouter>);
    expect(screen.getByText('Sarah')).toBeInTheDocument();
    expect(screen.getByText('Hair Cut')).toBeInTheDocument();
    expect(screen.getByText(/150/)).toBeInTheDocument();
    expect(screen.getByText('Aisha')).toBeInTheDocument();
  });

  it('shows a fallback when opened directly without state', () => {
    render(<MemoryRouter initialEntries={['/booking/preview']}><BookingPreview /></MemoryRouter>);
    expect(screen.getByText(/no simulation/i)).toBeInTheDocument();
  });
});
