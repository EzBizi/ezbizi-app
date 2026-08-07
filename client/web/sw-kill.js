// A service worker whose only job is to remove itself.
//
// ── Why this file exists ─────────────────────────────────────────
//
// Earlier builds shipped Flutter's generated service worker, which caches
// main.dart.js in Cache Storage and serves it ahead of the network. Cache
// Storage does not care what Cache-Control says, so fixing the nginx headers
// changed nothing for anybody who already had it installed: they kept running
// a build from whenever they first loaded the app.
//
// Building with --pwa-strategy=none stops REGISTERING a new one. It does not
// remove one already installed — nothing does, from the page's side, once the
// worker is controlling navigation and serving its own cached index.html. The
// page that would unregister it is the page it refuses to update.
//
// The way out is this file. A browser with the old worker re-fetches its script
// on every navigation to check for updates; finding different bytes, it installs
// what it finds. So the replacement is a worker that immediately deletes every
// cache, unregisters itself, and reloads whatever tabs it was controlling.
//
// It must keep being served — deleting it makes that update check 404, which
// leaves the old worker in place indefinitely. It costs nothing and it is the
// only thing standing between a stale install and a permanently stale app.

self.addEventListener('install', () => {
  // No waiting for the old worker's tabs to close. That is the state we are
  // trying to escape.
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // Every cache, not just the ones Flutter named. A build from months ago used
    // different keys, and leaving one behind leaves the problem behind.
    const keys = await caches.keys();
    await Promise.all(keys.map((key) => caches.delete(key)));

    await self.registration.unregister();

    // Reload the tabs this worker was controlling, so they pick up the real
    // assets from the network. Without it somebody sits on the stale page until
    // they happen to refresh — which is exactly what they have been doing.
    const clients = await self.clients.matchAll({ type: 'window' });

    for (const client of clients) {
      client.navigate(client.url);
    }
  })());
});

// No fetch handler at all. An installed worker with none lets every request go
// straight to the network, so even in the moment before it unregisters it is
// already out of the way.
