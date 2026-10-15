<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$config = loadConfig();
$client = currentApiClient();

$res = $client->request('GET', '/api/ics-export');
handleUnauthorized($res);
$token = $res['ok'] ? $res['data']['token'] : null;
$loadError = $res['ok'] ? null : ($res['data']['error'] ?? 'Status konnte nicht geladen werden.');

$feedUrl = null;
$webcalUrl = null;
if ($token) {
    $base = rtrim((string) $config['api_base_url'], '/');
    $feedUrl = $base . '/api/ics/' . $token . '.ics';
    $webcalUrl = preg_replace('/^https?:\/\//i', 'webcal://', $feedUrl);
}

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Kalender-Abo (ICS-Export)</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="main-area">
  <div class="form-card" style="max-width:640px;">
    <h1>Kalender-Abo (ICS-Export)</h1>
    <p class="hint-text">
      Mit diesem Link kann der Familienkalender in externen
      Kalender-Apps (Google Kalender, Outlook, Apple Kalender, …) als
      Abo eingetragen werden - read-only, wird dort automatisch
      regelmäßig aktualisiert. Enthält Termine von 6 Monaten zurück bis
      2 Jahre voraus.
    </p>

    <?php if ($notice): ?><p class="success-box"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($loadError): ?><p class="error-box"><?= h($loadError) ?></p><?php endif; ?>

    <?php if ($feedUrl): ?>
      <label>Abo-Link</label>
      <input type="text" value="<?= h($feedUrl) ?>" readonly />
      <p class="hint-text">
        Diesen Link in der Kalender-App als „Kalender abonnieren“ bzw.
        „per URL hinzufügen“ eintragen. Manche Apps akzeptieren auch
        direkt <code><?= h($webcalUrl) ?></code>.
      </p>

      <div class="form-actions">
        <form method="post" action="ics_export_generate.php" style="margin:0;">
          <?= csrfField() ?>
          <button type="submit" class="btn btn-secondary">Link erneuern (alter Link wird ungültig)</button>
        </form>
        <form method="post" action="ics_export_revoke.php" style="margin:0;">
          <?= csrfField() ?>
          <button type="submit" class="btn btn-danger">Abo deaktivieren</button>
        </form>
      </div>
    <?php else: ?>
      <form method="post" action="ics_export_generate.php">
        <?= csrfField() ?>
        <button type="submit" class="btn btn-primary">Abo-Link erzeugen</button>
      </form>
    <?php endif; ?>

    <p style="margin-top:20px;">
      <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
    </p>
  </div>
</div>
</body>
</html>
