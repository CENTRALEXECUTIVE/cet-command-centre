/*
 * CET Command Centre service worker.
 *
 * Caching policy (privacy first):
 *  - Only STATIC assets are ever cached (CSS, JS, icons, manifest, offline page).
 *  - HTML/pages, booking data, customer data and GPS feeds are NEVER cached —
 *    every navigation goes to the network, and if the network is down the
 *    static offline page is shown instead. Nothing sensitive persists on the
 *    device beyond the normal browser session.
 *
 * Bump CACHE_VERSION whenever a precached asset changes so clients refresh.
 */
const CACHE_VERSION = 'cet-static-v11';

const PRECACHE = [
    '/offline.html',
    '/css/app.css',
    '/js/cet-forms.js',
    '/js/cet-flight.js',
    '/js/cet-track.js',
    '/js/cet-push.js',
    '/js/cet-alerts.js',
    '/js/cet-ops.js',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
    '/icons/apple-touch-icon.png',
    '/manifest.webmanifest',
];

// Only these extensions are cache-eligible — everything else is network-only.
const STATIC_RE = /\.(css|js|png|jpg|jpeg|svg|gif|ico|webmanifest|woff2?)$/;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Page navigations: always network, offline fallback. Never cached.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html'))
        );
        return;
    }

    // Same-origin static assets: stale-while-revalidate.
    if (url.origin === self.location.origin && STATIC_RE.test(url.pathname)) {
        event.respondWith(
            caches.open(CACHE_VERSION).then((cache) =>
                cache.match(request).then((cached) => {
                    const refresh = fetch(request)
                        .then((response) => {
                            if (response && response.ok) cache.put(request, response.clone());
                            return response;
                        })
                        .catch(() => cached);
                    return cached || refresh;
                })
            )
        );
    }
    // Everything else (JSON feeds, cross-origin tiles/fonts): straight to network.
});

/* ── Push notifications (driver new-job alerts) ─────────────────────────── */
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = {}; }
    const title = data.title || 'Central Executive Transfers';
    const options = {
        body: data.body || '',
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        tag: data.tag || undefined,
        renotify: !!data.tag,
        data: { url: data.url || '/driver/jobs' },
        vibrate: [80, 40, 80],
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/driver/jobs';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) { client.navigate(target); return client.focus(); }
            }
            return self.clients.openWindow(target);
        })
    );
});
