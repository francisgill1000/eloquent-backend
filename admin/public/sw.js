// Service worker for web-push notifications (new WhatsApp messages).
// The backend (ProcessWaReply) pushes {title, body, tag} payloads.

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    /* non-JSON payload — show a generic notification */
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'New message', {
      body: data.body || '',
      tag: data.tag || undefined, // one notification per contact, newest wins
      renotify: Boolean(data.tag), // re-alert (sound/buzz) on repeat messages from the same contact
      vibrate: [80, 40, 80],
      requireInteraction: true,    // stay on screen until dismissed — a lead is easy to miss
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if ('focus' in client) return client.focus();
      }
      return self.clients.openWindow('/');
    })
  );
});
