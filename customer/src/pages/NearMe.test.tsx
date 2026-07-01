import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import NearMe from './NearMe';

vi.mock('@/lib/api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
import api from '@/lib/api';

beforeEach(() => {
  vi.clearAllMocks();
  (api.get as any).mockResolvedValue({ data: { data: [{ id: 2, name: 'Near Spa', is_open: true, distance_km: 1.2 }] } });
  Object.defineProperty(global.navigator, 'geolocation', {
    configurable: true,
    value: { getCurrentPosition: (ok: any) => ok({ coords: { latitude: 25.1, longitude: 55.2, accuracy: 10 } }) },
  });
});

describe('NearMe', () => {
  it('requests nearby shops with coordinates', async () => {
    render(<MemoryRouter><NearMe /></MemoryRouter>);
    await waitFor(() => expect(screen.getByText('Near Spa')).toBeInTheDocument());
    expect(api.get).toHaveBeenCalledWith('/shops/nearby', { params: { lat: 25.1, lon: 55.2, radius: 10 } });
  });

  it('shows an error state when geolocation is denied', async () => {
    Object.defineProperty(global.navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition: (_ok: any, err: any) => err({ code: 1, message: 'denied' }) },
    });
    render(<MemoryRouter><NearMe /></MemoryRouter>);
    await waitFor(() => expect(screen.getByText('Location Unavailable')).toBeInTheDocument());
  });
});
