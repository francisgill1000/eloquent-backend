import { describe, it, expect } from 'vitest';
import { getDeviceId } from './deviceId';

describe('getDeviceId', () => {
  it('generates a v4 uuid and persists it', () => {
    const id = getDeviceId();
    expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(localStorage.getItem('device_id')).toBe(id);
  });

  it('returns the same id on subsequent calls', () => {
    const first = getDeviceId();
    const second = getDeviceId();
    expect(second).toBe(first);
  });
});
