/**
 * KMoney Service Worker
 * Strategie: Cache-first per asset statici, Network-first per pagine HTML.
 */

const CACHE_VERSION = 'kmoney-v2';
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

// Pattern di URL da escludere sempre (form submit, admin, API, tutte le pagine portal)
// Le pagine HTML contengono CSRF token legati alla sessione: non vanno mai servite
// dalla cache perché causano errore 419 quando il token scade (comune su mobile).
const BYPASS_PATTERNS = [
    /\/admin\//,
    /\/(login|logout|register)/,
    /\/(paga|incassa)/,
    /\/portal\//,
    /\/dashboard/,
    /\/movimenti/,
    /\/richieste/,
    /\/wallet/,
    /\/profilo/,
    /\/sicurezza/,
    /\/sessioni/,
    /\/notifiche/,
    /\/broker/,
    /\/aziende/,
    /\/annunci/,
    /\/shop/,
    /\/onboarding/,
    /\/push\//,
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


// ── Payment Handler API (W3C) ────────────────────────────────────────────────
// Permette al browser di mostrare uno sheet nativo "Paga con KMoney" quando
// un sito chiama: new PaymentRequest([{ supportedMethods: '...' }], details)

// Mappa paymentRequestId → { resolve, reject }
const pendingPayments = new Map();

self.addEventListener('canmakepayment', (event) => {
    // Il browser chiede: "Puoi gestire questo metodo?"
    event.respondWith(Promise.resolve(true));
});

self.addEventListener('paymentrequest', (event) => {
    event.respondWith(
        (async () => {
            const total   = event.total;
            const params  = new URLSearchParams({
                amount:    total.value,
                currency:  total.amount?.currency ?? 'KY',
                label:     total.label,
                pr_id:     event.paymentRequestId ?? '',
            });

            // Apre la finestra di conferma KY nel browser payment sheet
            const client = await event.openWindow(
                self.registration.scope + 'paga/handler?' + params.toString()
            );

            if (!client) {
                throw new Error('Impossibile aprire la finestra di pagamento.');
            }

            // Attende la risposta della finestra via postMessage
            return new Promise((resolve, reject) => {
                const prId = event.paymentRequestId;
                pendingPayments.set(prId, { resolve, reject });

                // Timeout 5 minuti
                setTimeout(() => {
                    if (pendingPayments.has(prId)) {
                        pendingPayments.delete(prId);
                        reject(new DOMException('Pagamento scaduto.', 'AbortError'));
                    }
                }, 300_000);
            });
        })()
    );
});

self.addEventListener('message', (event) => {
    const data = event.data;
    if (!data || !data.type || !data.pr_id) return;

    const pending = pendingPayments.get(data.pr_id);
    if (!pending) return;
    pendingPayments.delete(data.pr_id);

    if (data.type === 'ky-payment-confirm') {
        pending.resolve({
            methodName: data.methodName,
            details:    { success: true, transferUuid: data.transferUuid ?? null },
        });
    } else if (data.type === 'ky-payment-cancel') {
        pending.reject(new DOMException('Annullato dall\'utente.', 'AbortError'));
    }
});
