// Web-push subscription for new-message notifications (master only — the
// backend broadcasts every inbound message to all subscriptions).
// Backend endpoints: /wa/push/vapid-key, /wa/push/subscribe, /wa/push/unsubscribe.
import api from './api';

export function pushSupported(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

async function swRegistration(): Promise<ServiceWorkerRegistration> {
  const reg = await navigator.serviceWorker.register('/sw.js');
  await navigator.serviceWorker.ready;
  return reg;
}

/** Whether this browser currently has an active push subscription. */
export async function pushEnabled(): Promise<boolean> {
  if (!pushSupported() || Notification.permission !== 'granted') return false;
  const reg = await navigator.serviceWorker.getRegistration('/sw.js');
  const sub = await reg?.pushManager.getSubscription();
  return Boolean(sub);
}

/** Ask permission, subscribe this browser, and register it with the backend. */
export async function enablePush(): Promise<void> {
  if (!pushSupported()) throw new Error('Notifications are not supported in this browser.');

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') throw new Error('Notification permission was not granted.');

  const { data } = await api.get<{ key: string }>('/wa/push/vapid-key');

  const reg = await swRegistration();
  const sub =
    (await reg.pushManager.getSubscription()) ??
    (await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(data.key),
    }));

  await api.post('/wa/push/subscribe', sub.toJSON());
}

/** Unregister this browser from the backend and drop the subscription. */
export async function disablePush(): Promise<void> {
  const reg = await navigator.serviceWorker.getRegistration('/sw.js');
  const sub = await reg?.pushManager.getSubscription();
  if (!sub) return;
  await api.post('/wa/push/unsubscribe', { endpoint: sub.endpoint }).catch(() => undefined);
  await sub.unsubscribe();
}

function urlBase64ToUint8Array(base64: string) {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4);
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
  // Explicit ArrayBuffer backing so TS accepts it as a BufferSource.
  const out = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}
