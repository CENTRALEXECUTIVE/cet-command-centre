// Offline cache for the app files. Your data is in IndexedDB, never in this cache.
const CACHE = 'money-hq-v3';
const FILES = ['./', 'index.html', 'css/app.css', 'js/app.js', 'js/calc.js', 'js/charts.js', 'js/importers.js', 'js/store.js', 'js/ui.js', 'js/views-core.js', 'js/views-plan.js', 'js/views-import.js', 'js/views-insights.js', 'js/insights.js', 'manifest.webmanifest', 'icon.svg'];
self.addEventListener('install', (e) => e.waitUntil(caches.open(CACHE).then((c) => c.addAll(FILES)).then(() => self.skipWaiting())));
self.addEventListener('activate', (e) => e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())));
// Network first so updates arrive; fall back to cache when offline.
self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET' || new URL(e.request.url).origin !== location.origin) return;
  e.respondWith(fetch(e.request).then((r) => { const copy = r.clone(); caches.open(CACHE).then((c) => c.put(e.request, copy)); return r; }).catch(() => caches.match(e.request)));
});
