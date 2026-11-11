/* ComfortBook service worker: offline shell, fast static assets, push notifications. */
const V = 'cb-v6', STATIC = 'cb-static-' + V, BASE = self.registration.scope;
self.addEventListener('install', e => { e.waitUntil(caches.open(STATIC).then(c => c.addAll([BASE + 'offline.html', BASE + 'assets/vendor/bootstrap/bootstrap.min.css', BASE + 'assets/vendor/bootstrap/bootstrap.bundle.min.js', BASE + 'assets/vendor/fontawesome/css/all.min.css', BASE + 'assets/logo/ueab.png']).catch(() => {})).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k.startsWith('cb-static-') && k !== STATIC).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin || u.pathname.includes('/api/')) return;       // never cache live data
  if (u.pathname.includes('/assets/')) {                                                                  // stale-while-revalidate
    e.respondWith(caches.open(STATIC).then(async c => { const hit = await c.match(r); const net = fetch(r).then(res => { if (res.ok) c.put(r, res.clone()); return res; }).catch(() => hit); return hit || net; })); return;
  }
  if (r.mode === 'navigate') e.respondWith(fetch(r).catch(() => caches.match(BASE + 'offline.html')));
});
self.addEventListener('push', e => {
  let d = {}; try { d = e.data ? e.data.json() : {}; } catch (_) { d = { title: 'ComfortBook', body: e.data && e.data.text() }; }
  e.waitUntil(self.registration.showNotification(d.title || 'ComfortBook', { body: d.body || '', icon: BASE + 'assets/logo/ueab.png', badge: BASE + 'assets/logo/ueab.png', tag: d.kind || 'cb', renotify: true, data: { url: d.url || '/' }, vibrate: [120, 60, 120] }));
});
self.addEventListener('notificationclick', e => {
  e.notification.close(); const u = new URL(e.notification.data.url || '/', BASE).href;
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(ws => { for (const w of ws) { if ('focus' in w) { w.navigate(u); return w.focus(); } } return clients.openWindow(u); }));
});
