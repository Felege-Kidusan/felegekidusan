var CACHE_NAME = (self.SCHOOL_CACHE || 'school') + '-v5';
var SHELL_FILES = [
    '/app/',
    '/app/index.php',
    '/app/css/app.css',
    '/app/js/app.js',
    '/app/manifest.php',
    '/app/icons/icon-192.svg',
    '/app/icons/icon-512.svg'
];

// Install: cache shell files
self.addEventListener('install', function(e) {
    e.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(SHELL_FILES);
        })
    );
    self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', function(e) {
    e.waitUntil(
        caches.keys().then(function(names) {
            return Promise.all(
                names.filter(function(n) { return n !== CACHE_NAME; })
                     .map(function(n) { return caches.delete(n); })
            );
        })
    );
    self.clients.claim();
});

// Fetch: smart strategy
self.addEventListener('fetch', function(e) {
    var url = new URL(e.request.url);

    // Skip non-GET and API calls
    if (e.request.method !== 'GET') return;
    if (url.pathname.indexOf('api_') !== -1) return;
    if (url.pathname.indexOf('app_session') !== -1) return;

    // App shell files: cache-first
    if (url.pathname.indexOf('/app/') === 0) {
        e.respondWith(
            caches.match(e.request).then(function(cached) {
                if (cached) {
                    // Return cache immediately, update in background
                    var fetchPromise = fetch(e.request).then(function(response) {
                        if (response.ok) {
                            caches.open(CACHE_NAME).then(function(cache) {
                                cache.put(e.request, response);
                            });
                        }
                        return response.clone();
                    }).catch(function() {});
                    return cached;
                }
                // Not in cache: fetch, cache, return
                return fetch(e.request).then(function(response) {
                    if (response.ok) {
                        var clone = response.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(e.request, clone);
                        });
                    }
                    return response;
                }).catch(function() {
                    // Offline fallback for navigation
                    if (e.request.mode === 'navigate') {
                        return caches.match('/app/index.php');
                    }
                    return new Response('', { status: 503 });
                });
            })
        );
        return;
    }

    // External resources (fonts, icons CDN): cache with fallback
    if (url.hostname !== location.hostname) {
        e.respondWith(
            caches.match(e.request).then(function(cached) {
                return cached || fetch(e.request).then(function(response) {
                    if (response.ok) {
                        var clone = response.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(e.request, clone);
                        });
                    }
                    return response;
                }).catch(function() {
                    return new Response('');
                });
            })
        );
    }
});
