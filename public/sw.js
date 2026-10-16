// Service Worker für Web Push UND die Installierbarkeit als PWA
// (einziges JavaScript im Projekt - der Rest der App ist bewusst
// JS-frei, siehe README). Muss unter / liegen, damit sein Scope die
// ganze App abdeckt.
//
// Cache nur für das statische "Shell"-Material (CSS, Icons, Manifest) -
// die PHP-Seiten selbst bleiben bewusst UNcached: sie sind
// session-/mandantenabhängig (eigene Termine, CSRF-Token), ein
// gecachtes HTML-Fragment könnte veraltete oder falsche Daten eines
// anderen Logins zeigen.
const CACHE_NAME = 'familienkalender-shell-v1';
const SHELL_ASSETS = ['/styles.css', '/manifest.json', '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(SHELL_ASSETS);
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (key) { return key !== CACHE_NAME; }).map(function (key) { return caches.delete(key); })
      );
    })
  );
});

self.addEventListener('fetch', function (event) {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || !SHELL_ASSETS.includes(url.pathname)) {
    return; // alles andere (dynamische PHP-Seiten, API) normal ans Netz durchlassen
  }

  event.respondWith(
    caches.match(event.request).then(function (cached) {
      return cached || fetch(event.request);
    })
  );
});

self.addEventListener('push', function (event) {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'Familienkalender', body: event.data ? event.data.text() : '' };
  }

  const title = data.title || 'Familienkalender';
  const options = {
    body: data.body || '',
    icon: '/icons/icon-192.png',
    data: { url: data.url || '/calendar.php' },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/calendar.php';
  event.waitUntil(self.clients.openWindow(url));
});
