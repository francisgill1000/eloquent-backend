import { storage } from './storage';

const KEY = 'booking_session_id';

function uuidv4(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

// The customer booking thread is keyed server-side by the X-Device-Id we send.
// Using a dedicated, rotatable id (not the app-wide device id) lets "New
// booking" start a fresh conversation while a reload keeps the current one.
export function getBookingSessionId(): string {
  let id = storage.get(KEY);
  if (!id) { id = uuidv4(); storage.set(KEY, id); }
  return id;
}

export function newBookingSession(): string {
  const id = uuidv4();
  storage.set(KEY, id);
  return id;
}
