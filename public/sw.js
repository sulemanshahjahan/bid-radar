// Minimal service worker so the app is installable. Pages are private and
// always fetched from the network; nothing is cached.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
