// ============================================================================
// CloudDrive Offline Service Worker (PWA 100% Offline Engine)
// Cache-First for static assets, Network-First with Cache fallback for navigation
// ============================================================================

const CACHE_NAME = 'clouddrive-offline-v1';

const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/p2p.js',
  '/favicon.svg',
  '/dist/index.html',
];

// 1. Install & Pre-cache App Shell
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[ServiceWorker] Pre-caching offline application shell');
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.warn('[ServiceWorker] Some pre-cache assets skipped:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

// 2. Activate & Clean old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            console.log('[ServiceWorker] Removing old cache:', key);
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// 3. Fetch Handler: Offline resilient
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Skip caching non-GET requests or streaming binary API calls
  if (request.method !== 'GET' || url.pathname.includes('/api/p2p.php?action=stream')) {
    return;
  }

  // A. Navigation (HTML pages)
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Cache fresh copy
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          return response;
        })
        .catch(() => {
          // Offline fallback: serve cached index.php or /
          return caches.match(request).then((cached) => {
            return cached || caches.match('/index.php') || caches.match('/');
          });
        })
    );
    return;
  }

  // B. Static Assets (CSS, JS, Fonts, Images)
  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        // Return from cache, revalidate in background
        fetch(request).then((fresh) => {
          if (fresh && fresh.status === 200) {
            caches.open(CACHE_NAME).then((cache) => cache.put(request, fresh));
          }
        }).catch(() => {});
        return cached;
      }

      // Fetch from network and cache
      return fetch(request).then((response) => {
        if (response && response.status === 200) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return response;
      }).catch(() => {
        // Fallback or empty response if fully offline and un-cached
        return new Response('Offline Asset Unavailable', { status: 503 });
      });
    })
  );
});
