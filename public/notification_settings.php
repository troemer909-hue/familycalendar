<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$prefsRes = $client->request('GET', '/api/notification-preferences');
handleUnauthorized($prefsRes);
$prefs = $prefsRes['ok'] ? $prefsRes['data'] : [
    'own_events' => true,
    'family_events' => true,
    'channel_mail' => true,
    'channel_push' => true,
    'reminder_default_minutes' => 30,
];
$prefsError = $prefsRes['ok'] ? null : ($prefsRes['data']['error'] ?? 'Einstellungen konnten nicht geladen werden.');

$saved = isset($_GET['saved']);
$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Benachrichtigungseinstellungen</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="main-area">
  <div class="form-card" style="max-width:560px;">
    <h1>Benachrichtigungseinstellungen</h1>
    <p class="hint-text">
      Diese Schalter gelten zusätzlich zu den einzelnen Erinnerungen, die
      du pro Termin anlegst: ist ein Schalter hier aus, wird keine fällige
      Erinnerung über den jeweiligen Weg zugestellt - die Erinnerung selbst
      bleibt aber bestehen und greift wieder, sobald du den Schalter hier
      erneut aktivierst.
    </p>

    <?php if ($saved): ?><p class="success-box">Gespeichert.</p><?php endif; ?>
    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($prefsError): ?><p class="error-box"><?= h($prefsError) ?></p><?php endif; ?>

    <form method="post" action="notification_settings_save.php">
      <h2 style="font-size:16px;margin-top:20px;">Für welche Termine?</h2>
      <label class="checkbox-label">
        <input type="checkbox" name="own_events" value="1" <?= $prefs['own_events'] ? 'checked' : '' ?> />
        Erinnerungen zu persönlichen Terminen
      </label>
      <label class="checkbox-label">
        <input type="checkbox" name="family_events" value="1" <?= $prefs['family_events'] ? 'checked' : '' ?> />
        Erinnerungen zu Familienterminen
      </label>

      <h2 style="font-size:16px;margin-top:20px;">Über welchen Kanal?</h2>
      <label class="checkbox-label">
        <input type="checkbox" name="channel_mail" value="1" <?= $prefs['channel_mail'] ? 'checked' : '' ?> />
        Per E-Mail
      </label>
      <label class="checkbox-label">
        <input type="checkbox" name="channel_push" value="1" <?= $prefs['channel_push'] ? 'checked' : '' ?> />
        Per Push-Benachrichtigung
      </label>
      <p class="hint-text">
        Push funktioniert nur, wenn du es zusätzlich für dieses Gerät
        aktiviert hast: <a href="push_subscribe.php">Push-Benachrichtigungen
        einrichten</a>.
      </p>

      <h2 style="font-size:16px;margin-top:20px;">Standard-Vorlaufzeit</h2>
      <div class="row">
        <select name="reminder_default_minutes">
          <?php foreach ([15 => '15 Minuten vorher', 30 => '30 Minuten vorher', 60 => '1 Stunde vorher', 120 => '2 Stunden vorher', 1440 => '1 Tag vorher', 2880 => '2 Tage vorher'] as $value => $label): ?>
            <option value="<?= $value ?>" <?= $prefs['reminder_default_minutes'] === $value ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="hint-text">Wird bei neuen Erinnerungen als Vorschlag vorausgewählt.</p>

      <p style="margin-top:20px;">
        <button type="submit" class="btn btn-primary">Speichern</button>
        <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
      </p>
    </form>
  </div>
</div>
</body>
</html>
