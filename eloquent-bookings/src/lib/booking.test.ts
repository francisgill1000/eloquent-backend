import { describe, it, expect } from 'vitest';
import { buildBookingPayload } from './booking';
import type { Service } from '@/types';

const catalogs: Service[] = [
  { id: 1, title: 'Cut', price: 50, image: 'a.png' },
  { id: 2, title: 'Color', price: 120, image: 'b.png' },
];

describe('buildBookingPayload', () => {
  it('includes date, time, total charges and strips images from services', () => {
    const payload = buildBookingPayload('2026-06-01', '10:00', catalogs, [1, 2]);
    expect(payload.date).toBe('2026-06-01');
    expect(payload.start_time).toBe('10:00');
    expect(payload.charges).toBe(170);
    expect(payload.services).toHaveLength(2);
    expect((payload.services[0] as any).image).toBeUndefined();
  });

  it('only includes selected services', () => {
    const payload = buildBookingPayload('2026-06-01', '10:00', catalogs, [2]);
    expect(payload.services).toHaveLength(1);
    expect(payload.charges).toBe(120);
  });
});
