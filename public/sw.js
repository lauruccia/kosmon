/**
 * KMoney Service Worker
 * Strategie: Cache-first per asset statici, Network-first per pagine HTML.
 */

const CACHE_VERSION = 'kmoney-v1';
const STATIC_CACHE  = `${CACHE_VERSION}-static`;
const PAGE_CACHE    = `${CACHE_VERSION}-pages`;

// Asset da pre-cacheare al primo install
const PRECACHE_URLS = [
    '/offline.html',
];

// Pattern di URL da cacheare come asset statici (cache-first)
const STATIC_PATTERNS = [
    /\/assets\//,
    /\/build\//,
    /\.(?:png|jpg|jpeg|svg|gif|webp|ico|woff2?|ttf|otf)$/i,
];

// Pattern di URL da escludere sempre (form submit, admin, API)
const BYPASS_PATTERNS = [
    /\/admin\//,
    /\/(login|logout|register)/,
    /\/(paga|incassa)\/?$/, // POST endpoints — non cacheare navigazioni critiche
];

// ── Install ─────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

// ── Activate — rimuovi cache vecchie ────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(key => key.startsWith('kmoney-') && key !== STATIC_CACHE && key !== PAGE_CACHE)
                    .map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

// ── Push notifications ───────────────────────────────────────────────
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'KMoney', body: event.data ? event.data.text() : '' };
    }

    const title   = data.title  ?? 'KMoney';
    const options = {
        body:    data.body   ?? '',
        icon:    data.icon   ?? '/assets/brand/icon-192.png',
        badge:   data.badge  ?? '/assets/brand/icon-192.png',
        tag:     data.tag    ?? 'kmoney-notification',
        data:    { url: data.url ?? '/dashboard' },
        vibrate: [200, 100, 200],
        requireInteraction: false,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = event.notification.data?.url ?? '/dashboard';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
            // Se c'e' gia' una finestra aperta sullo stesso origin, portala in focus
            for (const client of windowClients) {
                if (new URL(client.url).origin === self.location.origin) {
                    client.focus();
                    client.navigate(targetUrl);
                    return;
                }
            }
            // Altrimenti apri una nuova finestra
            return clients.openWindow(targetUrl);
        })
    );
});

// ── Fetch ────────────────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Solo GET, stesso origin
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Bypass per percorsi critici
    if (BYPASS_PATTERNS.some(p => p.test(url.pathname))) return;

    // Asset statici → cache-first
    if (STATIC_PATTERNS.some(p => p.test(url.pathname))) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(cache =>
                cache.match(request).then(cached => {
                    if (cached) return cached;
                    return fetch(request).then(response => {
                        if (response.ok) cache.put(request, response.clone());
                        return response;
                    });
                })
            )
        );
        return;
    }

    // Pagine HTML → network-first con fallback cache
    if (request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(PAGE_CACHE).then(cache => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() =>
                    caches.open(PAGE_CACHE).then(cache =>
                        cache.match(request).then(cached =>
                            cached || caches.match('/offline.html')
                        )
                    )
                )
        );
    }
});
