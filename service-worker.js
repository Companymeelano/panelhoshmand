/* سرویس‌ورکر پنل هوشمند میلانو — پشتیبانی نصب و حالت آفلاین */
const CACHE = 'meelano-panel-v1';
const APP_SHELL = [
  './',
  './index.html',
  './offline.html',
  './manifest.webmanifest',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/maskable-512.png',
  './icons/apple-touch-icon.png',
  './icons/favicon-32.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(APP_SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

async function networkFirst(request) {
  try {
    const fresh = await fetch(request);
    const cache = await caches.open(CACHE);
    cache.put(request, fresh.clone());
    return fresh;
  } catch (err) {
    const cached = await caches.match(request, { ignoreSearch: true });
    return cached || caches.match('./index.html');
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(CACHE);
  const cached = await cache.match(request);
  const updating = fetch(request)
    .then((fresh) => { cache.put(request, fresh.clone()); return fresh; })
    .catch(() => cached);
  return cached || updating;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);

  // ناوبری صفحات: اول شبکه، بعد کش
  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request));
    return;
  }
  // فایل‌های خود اپ: اول کش
  if (url.origin === self.location.origin) {
    event.respondWith(caches.match(request).then((hit) => hit || networkFirst(request)));
    return;
  }
  // CDN و تصاویر خارجی: stale-while-revalidate
  event.respondWith(staleWhileRevalidate(request));
});
