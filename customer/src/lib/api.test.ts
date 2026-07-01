import { describe, it, expect, beforeEach } from 'vitest';
import api from './api';

describe('api instance', () => {
  beforeEach(() => localStorage.clear());

  it('uses the default base URL when env is unset', () => {
    expect(api.defaults.baseURL).toBe('https://api.eloquentservice.com/api');
  });

  it('attaches X-Device-Id on every request', async () => {
    const config = await (api.interceptors.request as any).handlers[0].fulfilled({ headers: {} });
    expect(config.headers['X-Device-Id']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('attaches the customer token as a Bearer header when present', async () => {
    localStorage.setItem('customer_token', 'tok123');
    const config = await (api.interceptors.request as any).handlers[0].fulfilled({ headers: {} });
    expect(config.headers.Authorization).toBe('Bearer tok123');
  });

  it('omits Authorization when no token is stored', async () => {
    const config = await (api.interceptors.request as any).handlers[0].fulfilled({ headers: {} });
    expect(config.headers.Authorization).toBeUndefined();
  });
});
