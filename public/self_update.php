<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$statusRes = $client->request('GET', '/api/self-update/status');
handleUnauthorized($statusRes);
handleForbidden($statusRes);
$status = $statusRes['ok'] ? $statusRes['data'] : null;
$statusError = $statusRes['ok'] ? null : ($statusRes['data']['error'] ?? 'Status konnte nicht geladen werden.');

// Fehlermeldung aus self_update_run.php kommt bewusst aus der Session statt
// aus der URL (siehe dort) - einmal auslesen und sofort wieder entfernen
// (Flash-Message-Muster), damit sie nicht bei einem Reload erneut erscheint.
$error = $_SESSION['self_update_error'] ?? null;
unset($_SESSION['self_update_error']);
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Selbst-Update</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<div class="main-area">
  <div class="page-header"><?= brandLogo() ?></div>
  <div class="form-card" style="max-width:700px;">
    <h1>Selbst-Update</h1>
    <p class="hint-text">
      Zieht den aktuellen Stand per Git nach und wendet dabei automatisch
      alle neuen Datenbank-Migrationen an. Nur für Plattform-Administratoren
      (siehe <a href="admin_families.php">Plattform-Verwaltung</a>).
    </p>

    <?php if ($notice): ?><p class="success-box"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($statusError): ?><p class="error-box"><?= h($statusError) ?></p><?php endif; ?>

    <?php if ($status && !$status['enabled']): ?>
      <p class="error-box">
        Selbst-Update ist auf diesem Server nicht aktiviert. In der .env
        <code>ENABLE_SELF_UPDATE=1</code> setzen (und <code>SELF_UPDATE_BRANCH</code>
        prüfen), um diese Funktion freizuschalten.
      </p>
    <?php elseif ($status && !$status['git_available']): ?>
      <p class="error-box">
        Dieser Server ist kein Git-Checkout (kein <code>.git</code>-Ordner
        gefunden) - Selbst-Update per Git ist hier nicht möglich. Ein
        Update muss stattdessen manuell per neuem Paket hochgeladen werden.
      </p>
    <?php elseif ($status): ?>
      <table class="admin-table">
        <tr>
          <th>Branch</th>
          <td><code><?= h($status['branch']) ?></code></td>
        </tr>
        <tr>
          <th>Aktueller Stand</th>
          <td><code><?= h(substr((string) $status['current_commit'], 0, 12)) ?></code></td>
        </tr>
        <?php if ($status['error']): ?>
          <tr>
            <th>Remote-Prüfung</th>
            <td class="error-box"><?= h($status['error']) ?></td>
          </tr>
        <?php else: ?>
          <tr>
            <th>Verfügbarer Stand</th>
            <td><code><?= h(substr((string) $status['remote_commit'], 0, 12)) ?></code></td>
          </tr>
          <tr>
            <th>Status</th>
            <td><?= $status['up_to_date'] ? 'Aktuell' : 'Update verfügbar' ?></td>
          </tr>
        <?php endif; ?>
      </table>

      <form method="POST" action="self_update_run.php" style="margin-top:20px;">
        <?= csrfField() ?>
        <button type="submit" class="btn btn-primary">Jetzt aktualisieren</button>
      </form>
    <?php endif; ?>

    <p style="margin-top:20px;"><a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a></p>
  </div>
</div>
</body>
</html>
