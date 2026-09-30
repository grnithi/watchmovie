/* What To Watch - minimal service worker (makes the site installable).
 * Network-first for pages; falls back to cache when offline. API data is never cached here
 * (the server already caches it weekly). */
const CACHE = 'wtw-shell-v2';

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(['./'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== location.origin || url.pathname.includes('/api/')) return;

    e.respondWith(
        fetch(req, req.mode === 'navigate' ? { cache: 'no-cache' } : undefined) // pages: skip the browser HTTP cache
            .then((res) => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy));
                }
                return res;
            })
            .catch(() => caches.match(req).then((hit) => hit || caches.match('./')))
    );
});
