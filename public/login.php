<?php
require_once __DIR__ . '/../src/bootstrap.php';
$config = loadConfig();
$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender – Anmeldung</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <h1>📅 Familienkalender</h1>

      <form method="post" action="login_submit.php">
        <?= csrfField() ?>
        <label>API-Adresse des Backends</label>
        <input type="text" name="api_base_url" value="<?= h($config['api_base_url']) ?>" placeholder="https://family.eastcon.eu" required />

        <label>E-Mail</label>
        <input type="email" name="email" required />

        <label>Passwort</label>
        <input type="password" name="password" required />

        <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
      </form>

      <p class="switch-link">Noch keine Familie? <a href="register.php">Familie registrieren</a></p>
      <p class="switch-link"><a href="forgot_password.php">Passwort vergessen?</a></p>

      <?php if ($notice): ?>
        <p class="success-box"><?= h($notice) ?></p>
      <?php endif; ?>
      <?php if ($error): ?>
        <p class="error-box"><?= h($error) ?></p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
