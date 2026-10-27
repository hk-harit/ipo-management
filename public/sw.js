/*
 * Service worker for the installed app.
 *
 * The installed app is just the live website, so every deploy shows up the next time it
 * is opened — there is nothing to update by hand. To keep it that way this worker NEVER
 * serves a page from its cache: pages (which are per-login and full of live money figures)
 * always come from the network. The cache holds only the offline notice and static files
 * (css / js / fonts / images), used when the network is down.
 */
const VERSION = 'v2';
const CACHE = 'ipo-manage-' + VERSION;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png']))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Only plain same-origin GETs. Form posts, Livewire calls and other sites are left alone.
    if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/livewire')) {
        return;
    }

    // A page: always the network. If it is down, show the offline notice.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    // Static files: network first (so a deploy is never masked), cache as the fallback.
    if (['style', 'script', 'font', 'image'].includes(request.destination)) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request))
        );
    }
});
