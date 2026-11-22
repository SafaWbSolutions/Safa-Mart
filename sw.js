/* Safa Mart service worker.
 *
 * - Fast repeat visits: static assets are served from cache, pages use
 *   network-first so prices are never stale for long.
 * - Offline page instead of the browser dinosaur.
 * - Web Push: a real encrypted payload (title/body/url/image) is shown
 *   directly. Payload-less tickle pushes still work: the worker fetches
 *   /api/alerts.php and shows the newest deal.
 */

const VERSION = 'safa-v5';
const STATIC_CACHE = VERSION + '-static';
const PAGE_CACHE = VERSION + '-pages';
const OFFLINE_URL = '/offline.php';
const PAGE_CACHE_LIMIT = 40;
const STATIC_CACHE_LIMIT = 80;
const PRECACHE = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
    '/assets/icons/icon.svg'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .catch(() => undefined)
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.indexOf(VERSION + '-') !== 0)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return /^\/(assets\/|manifest\.webmanifest$|offline\.php$)/.test(url.pathname)
        || /\.(?:css|js|png|jpe?g|webp|svg|ico|woff2?)$/i.test(url.pathname);
}

/* Keep the caches from growing forever on long-lived installs. */
function trimCache(name, limit) {
    return caches.open(name)
        .then((cache) => cache.keys().then((keys) => {
            if (keys.length <= limit) {
                return undefined;
            }
            return Promise.all(keys.slice(0, keys.length - limit).map((key) => cache.delete(key)));
        }))
        .catch(() => undefined);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Never cache APIs, redirects or the Telegram webhook.
    if (/^\/(?:api\/|go\.php|sitemap\.xml|feed\.xml|robot)/.test(url.pathname)) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response && response.ok) {
                        const copy = response.clone();
                        caches.open(PAGE_CACHE)
                            .then((cache) => cache.put(request, copy))
                            .then(() => trimCache(PAGE_CACHE, PAGE_CACHE_LIMIT))
                            .catch(() => undefined);
                    }
                    return response;
                })
                .catch(() => caches.match(request)
                    .then((cached) => cached || caches.match(OFFLINE_URL)))
        );
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const network = fetch(request)
                    .then((response) => {
                        if (response && response.ok) {
                            const copy = response.clone();
                            caches.open(STATIC_CACHE)
                                .then((cache) => cache.put(request, copy))
                                .then(() => trimCache(STATIC_CACHE, STATIC_CACHE_LIMIT))
                                .catch(() => undefined);
                        }
                        return response;
                    })
                    .catch(() => cached);
                return cached || network;
            })
        );
    }
});

/* ---------- Web Push ---------- */

function showDealNotification(data) {
    const notification = data || {};
    const title = notification.title || 'New deal on Safa Mart';
    const options = {
        body: notification.body || 'Fresh offers just landed. Tap to see today\'s best prices.',
        icon: '/assets/icons/icon-192.png',
        badge: '/assets/icons/favicon-96.png',
        image: notification.image || undefined,
        tag: notification.tag || 'safa-deal',
        renotify: true,
        data: { url: notification.url || '/' },
        vibrate: [90, 40, 90],
        actions: [{ action: 'open', title: 'See deal' }]
    };
    return self.registration.showNotification(title, options);
}

async function fetchAlert() {
    try {
        const response = await fetch('/api/alerts.php?context=push', { cache: 'no-store' });
        if (!response.ok) {
            return null;
        }
        const payload = await response.json();
        if (payload && payload.ok && payload.notification) {
            return payload.notification;
        }
    } catch (error) {
        /* offline: fall back to the generic notification below */
    }
    return null;
}

self.addEventListener('push', (event) => {
    let payload = null;
    if (event.data) {
        try {
            payload = event.data.json();
        } catch (error) {
            try {
                const text = event.data.text();
                if (text) {
                    payload = { body: text };
                }
            } catch (textError) {
                payload = null;
            }
        }
    }

    event.waitUntil(
        (payload ? Promise.resolve(payload) : fetchAlert())
            .then((data) => showDealNotification(data))
            .catch(() => showDealNotification(null))
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if ('focus' in client) {
                    client.navigate(target).catch(() => undefined);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(target);
            }
            return undefined;
        })
    );
});

self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil((async () => {
        try {
            const configResponse = await fetch('/api/push.php?action=config', { cache: 'no-store' });
            const config = await configResponse.json();
            if (!config || !config.ok || !config.publicKey) {
                return;
            }
            const subscription = await self.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToBytes(config.publicKey)
            });
            await fetch('/api/push.php?action=subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ subscription: subscription.toJSON() })
            });
        } catch (error) {
            /* the page will re-subscribe on the next visit */
        }
    })());
});

function base64UrlToBytes(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        output[i] = raw.charCodeAt(i);
    }
    return output;
}

/* Page can ask for a local preview or a badge refresh. */
self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type === 'show-notification') {
        event.waitUntil(showDealNotification(data.notification || null));
    }
});
