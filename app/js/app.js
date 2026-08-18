/* Retired phone website. Unregister any leftover service worker. */
(function () {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function (regs) {
            regs.forEach(function (r) { r.unregister(); });
        });
    }
    if (window.caches) {
        caches.keys().then(function (keys) {
            keys.forEach(function (k) { caches.delete(k); });
        });
    }
})();
