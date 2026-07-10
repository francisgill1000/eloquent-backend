import { describe, it, expect, beforeEach } from 'vitest';
import { getBookingSessionId, newBookingSession } from './bookingSession';

describe('bookingSession', () => {
  beforeEach(() => localStorage.clear());

  it('creates and persists a stable id', () => {
    const a = getBookingSessionId();
    expect(a).toMatch(/[0-9a-f-]{36}/);
    expect(getBookingSessionId()).toBe(a);           // stable across calls
    expect(localStorage.getItem('booking_session_id')).toBe(a);
  });

  it('rotates to a new id on newBookingSession', () => {
    const a = getBookingSessionId();
    const b = newBookingSession();
    expect(b).not.toBe(a);
    expect(getBookingSessionId()).toBe(b);           // now the current id
  });
});
