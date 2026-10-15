// Minimaler Service Worker nur für Web Push (einziges JavaScript im
// Projekt - der Rest der App ist bewusst JS-frei, siehe README). Muss
// unter / liegen, damit sein Scope die ganze App abdeckt.

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
    icon: null,
    data: { url: data.url || '/calendar.php' },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/calendar.php';
  event.waitUntil(self.clients.openWindow(url));
});
