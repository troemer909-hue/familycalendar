<?php
require_once __DIR__ . '/../src/bootstrap.php';
$config = loadConfig();
$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender – Registrierung</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <h1>📅 Neue Familie anlegen</h1>

      <form method="post" action="register_submit.php">
        <?= csrfField() ?>
        <label>API-Adresse des Backends</label>
        <input type="text" name="api_base_url" value="<?= h($config['api_base_url']) ?>" placeholder="https://family.eastcon.eu" required />

        <label>Familienname</label>
        <input type="text" name="family_name" placeholder="Familie Muster" required />

        <label>Dein Name</label>
        <input type="text" name="admin_name" placeholder="Anna" required />

        <label>E-Mail</label>
        <input type="email" name="email" required />

        <label>Passwort (min. 8 Zeichen)</label>
        <input type="password" name="password" minlength="8" required />

        <button type="submit" class="btn btn-primary btn-block">Familie anlegen</button>
      </form>

      <p class="switch-link">Schon registriert? <a href="login.php">Zur Anmeldung</a></p>

      <?php if ($error): ?>
        <p class="error-box"><?= h($error) ?></p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
