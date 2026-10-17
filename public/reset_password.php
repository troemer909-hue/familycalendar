<?php
require_once __DIR__ . '/../src/bootstrap.php';
$error = $_GET['error'] ?? null;
$token = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender – Passwort zurücksetzen</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
  <div class="auth-standalone">
    <?= brandLogo('', 'login.php') ?>
    <div class="login-card">
      <h1>Passwort zurücksetzen</h1>
      <p class="hint-text card-intro">
        Trage den Code bzw. Link aus der E-Mail sowie dein neues Passwort ein.
      </p>

      <form method="post" action="reset_password_submit.php">
        <?= csrfField() ?>
        <label>Code aus der E-Mail</label>
        <input type="text" name="token" value="<?= h($token) ?>" required autofocus />

        <label>Neues Passwort (min. 8 Zeichen)</label>
        <input type="password" name="password" minlength="8" required />

        <label>Neues Passwort wiederholen</label>
        <input type="password" name="password_confirm" minlength="8" required />

        <button type="submit" class="btn btn-primary btn-block">Passwort ändern</button>
      </form>

      <?php if ($error): ?>
        <p class="error-box"><?= h($error) ?></p>
      <?php endif; ?>

      <p class="switch-link"><a href="forgot_password.php">Neuen Code anfordern</a></p>
      <p class="switch-link"><a href="login.php">Zurück zur Anmeldung</a></p>
    </div>
    <footer class="auth-footer">
      <a href="impressum.php">Impressum</a><span class="sep">·</span><a href="datenschutz.php">Datenschutz</a>
    </footer>
  </div>
</body>
</html>
