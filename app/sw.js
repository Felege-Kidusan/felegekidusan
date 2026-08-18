/* Kill switch: uninstall the old phone-website service worker and drop its cache. */
self.addEventListener('install', function (e) {
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(names.map(function (n) { return caches.delete(n); }));
        }).then(function () {
            return self.registration.unregister();
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (e) {
    e.respondWith(
        fetch(e.request).catch(function () {
            return new Response(
                'This phone website is closed. Open the main website to sign in.',
                { headers: { 'Content-Type': 'text/plain; charset=utf-8' } }
            );
        })
    );
});
