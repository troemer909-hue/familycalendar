<?php
require_once __DIR__ . '/../src/bootstrap.php';
$config = loadConfig();
$error = $_GET['error'] ?? null;
$sent = isset($_GET['sent']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender – Passwort vergessen</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <h1>📅 Passwort vergessen</h1>

      <?php if ($sent): ?>
        <p class="success-box">
          Falls ein Konto mit dieser E-Mail-Adresse existiert, wurde ein
          Link bzw. Code zum Zurücksetzen des Passworts versendet
          (60 Minuten gültig).
        </p>
        <p class="switch-link"><a href="reset_password.php">Code schon erhalten? Weiter zum Zurücksetzen</a></p>
      <?php else: ?>
        <p class="hint-text">
          Gib deine E-Mail-Adresse ein - falls dazu ein Konto existiert,
          bekommst du einen Link zum Zurücksetzen des Passworts.
        </p>

        <form method="post" action="forgot_password_submit.php">
          <?= csrfField() ?>
          <label>API-Adresse des Backends</label>
          <input type="text" name="api_base_url" value="<?= h($config['api_base_url']) ?>" placeholder="https://family.eastcon.eu" required />

          <label>E-Mail</label>
          <input type="email" name="email" required />

          <button type="submit" class="btn btn-primary btn-block">Link anfordern</button>
        </form>

        <?php if ($error): ?>
          <p class="error-box"><?= h($error) ?></p>
        <?php endif; ?>
      <?php endif; ?>

      <p class="switch-link"><a href="login.php">Zurück zur Anmeldung</a></p>
    </div>
  </div>
</body>
</html>
