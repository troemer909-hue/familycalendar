<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();
$vapidRes = $client->request('GET', '/api/push/vapid-public-key');
handleUnauthorized($vapidRes);

$pushConfigured = $vapidRes['ok'];
$vapidPublicKey = $pushConfigured ? ($vapidRes['data']['publicKey'] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Push-Benachrichtigungen</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<div class="main-area">
  <div class="form-card" style="max-width:560px;">
    <h1>Push-Benachrichtigungen</h1>
    <p class="hint-text">
      Einziges JavaScript im Projekt - für Web Push gibt es keinen Weg
      ohne Service Worker. Der Rest der App bleibt wie gewohnt reines PHP.
    </p>

    <?php if (!$pushConfigured): ?>
      <p class="error-box">
        Push ist auf diesem Server noch nicht eingerichtet (fehlende
        VAPID-Schlüssel oder <code>composer install</code> wurde noch
        nicht ausgeführt). Erinnerungen kommen bis dahin per E-Mail.
      </p>
    <?php else: ?>
      <p id="push-status" class="hint-text">Status wird geprüft …</p>
      <button id="push-toggle-btn" class="btn btn-primary" disabled>Bitte warten …</button>
      <p class="hint-text" style="margin-top:16px;">
        Aktiviert, bekommst du Erinnerungen zusätzlich zur E-Mail direkt
        als Benachrichtigung auf diesem Gerät/Browser - pro Gerät einmal
        einzurichten.
      </p>
    <?php endif; ?>

    <p style="margin-top:20px;">
      <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
    </p>
  </div>
</div>

<?php if ($pushConfigured): ?>
<script>
  const vapidPublicKey = <?= json_encode($vapidPublicKey) ?>;
  const csrfToken = <?= json_encode(csrfToken()) ?>;
  const statusEl = document.getElementById('push-status');
  const btn = document.getElementById('push-toggle-btn');

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  function setButton(subscribed) {
    btn.disabled = false;
    btn.textContent = subscribed ? 'Push deaktivieren' : 'Push aktivieren';
    btn.dataset.subscribed = subscribed ? '1' : '0';
    statusEl.textContent = subscribed
      ? 'Push-Benachrichtigungen sind auf diesem Gerät aktiv.'
      : 'Push-Benachrichtigungen sind auf diesem Gerät noch nicht aktiv.';
  }

  function fail(message) {
    statusEl.textContent = message;
    btn.disabled = false;
    btn.textContent = 'Erneut versuchen';
  }

  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    statusEl.textContent = 'Dieser Browser unterstützt keine Push-Benachrichtigungen.';
    btn.remove();
  } else {
    navigator.serviceWorker.register('/sw.js')
      .then(function (registration) {
        return registration.pushManager.getSubscription();
      })
      .then(function (subscription) {
        setButton(!!subscription);
      })
      .catch(function (err) {
        fail('Fehler beim Prüfen des Push-Status: ' + err.message);
      });

    btn.addEventListener('click', function () {
      btn.disabled = true;
      const currentlySubscribed = btn.dataset.subscribed === '1';

      navigator.serviceWorker.ready.then(function (registration) {
        if (currentlySubscribed) {
          registration.pushManager.getSubscription().then(function (subscription) {
            if (!subscription) {
              setButton(false);
              return;
            }
            const endpoint = subscription.endpoint;
            subscription.unsubscribe().then(function () {
              fetch('push_unsubscribe_save.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: endpoint, csrf_token: csrfToken }),
              }).finally(function () {
                setButton(false);
              });
            });
          });
          return;
        }

        registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        }).then(function (subscription) {
          // subscription.toJSON() liefert endpoint + keys.p256dh/auth
          // bereits fertig base64url-kodiert vom Browser - keine eigene
          // Kodierung nötig (und damit keine Fehlerquelle).
          const json = subscription.toJSON();
          const payload = {
            endpoint: json.endpoint,
            keys: json.keys,
            device_info: navigator.userAgent.substring(0, 255),
            csrf_token: csrfToken,
          };

          return fetch('push_subscribe_save.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          }).then(function (res) {
            if (!res.ok) {
              throw new Error('Server hat das Abo nicht gespeichert (' + res.status + ').');
            }
            setButton(true);
          });
        }).catch(function (err) {
          fail('Aktivieren fehlgeschlagen: ' + err.message);
        });
      });
    });
  }
</script>
<?php endif; ?>
</body>
</html>
