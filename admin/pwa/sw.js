/**
 * SheetSync PWA service worker — caches last dashboard snapshot for offline view.
 */
'use strict';

var CACHE = 'sheetsync-pwa-v1';

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') {
        return;
    }
    event.respondWith(
        fetch(req).catch(function () {
            return caches.match(req);
        })
    );
});
