<?php
require_once __DIR__ . '/../src/bootstrap.php';
$config = loadConfig();
$error = $_GET['error'] ?? null;
$token = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender – Einladung annehmen</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <h1>📅 Einladung annehmen</h1>
      <p class="hint-text">
        Trage den Code bzw. Link aus der E-Mail sowie dein gewünschtes
        Passwort ein - danach bist du direkt angemeldet.
      </p>

      <form method="post" action="accept_invite_submit.php">
        <?= csrfField() ?>
        <label>API-Adresse des Backends</label>
        <input type="text" name="api_base_url" value="<?= h($config['api_base_url']) ?>" placeholder="https://family.eastcon.eu" required />

        <label>Code aus der E-Mail</label>
        <input type="text" name="token" value="<?= h($token) ?>" required />

        <label>Passwort (min. 8 Zeichen)</label>
        <input type="password" name="password" minlength="8" required />

        <label>Passwort wiederholen</label>
        <input type="password" name="password_confirm" minlength="8" required />

        <button type="submit" class="btn btn-primary btn-block">Passwort festlegen & anmelden</button>
      </form>

      <?php if ($error): ?>
        <p class="error-box"><?= h($error) ?></p>
      <?php endif; ?>

      <p class="switch-link"><a href="login.php">Zurück zur Anmeldung</a></p>
    </div>
  </div>
</body>
</html>
