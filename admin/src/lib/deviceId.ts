import { storage } from './storage';

function uuidv4(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

let cached: string | null = null;

export function getDeviceId(): string {
  if (cached) return cached;
  let id = storage.get('device_id');
  if (!id) {
    id = uuidv4();
    storage.set('device_id', id);
  }
  cached = id;
  return id;
}
