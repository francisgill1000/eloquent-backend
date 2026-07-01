import type { Service } from '@/types';

export type BookingPayload = {
  date: string;
  start_time: string;
  charges: number;
  services: Omit<Service, 'image'>[];
};

export function buildBookingPayload(
  date: string,
  startTime: string,
  catalogs: Service[],
  selectedIds: number[],
): BookingPayload {
  const selected = catalogs.filter((c) => selectedIds.includes(c.id));
  const charges = selected.reduce((sum, s) => sum + (s.price != null ? parseFloat(String(s.price)) : 0), 0);
  const services = selected.map(({ image, ...rest }) => rest);
  return { date, start_time: startTime, charges, services };
}
