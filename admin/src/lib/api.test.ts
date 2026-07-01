import { describe, it, expect, beforeEach } from 'vitest';
import api from './api';
import { storage } from './storage';

type Handler = { fulfilled: (cfg: { headers: Record<string, string> }) => Promise<{ headers: Record<string, string> }> };

function runInterceptor() {
  const handler = (api.interceptors.request as unknown as { handlers: Handler[] }).handlers[0];
  return handler.fulfilled({ headers: {} });
}

describe('api client', () => {
  beforeEach(() => localStorage.clear());

  it('uses the shop_token for Authorization', async () => {
    storage.set('shop_token', 'tok-123');
    const cfg = await runInterceptor();
    expect(cfg.headers.Authorization).toBe('Bearer tok-123');
  });

  it('attaches X-Device-Id', async () => {
    const cfg = await runInterceptor();
    expect(cfg.headers['X-Device-Id']).toBeTruthy();
  });

  it('does not set Authorization when no token', async () => {
    const cfg = await runInterceptor();
    expect(cfg.headers.Authorization).toBeUndefined();
  });
});
