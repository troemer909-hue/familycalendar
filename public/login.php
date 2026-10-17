<?php
require_once __DIR__ . '/../src/bootstrap.php';
$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender – Anmeldung</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
  <div class="auth-layout">
    <aside class="auth-panel">
      <?= brandLogo('', 'login.php') ?>
      <div class="auth-panel-copy">
        <p class="eyebrow">Die Familien-App</p>
        <h1>Ein Kalender, den die ganze Familie wirklich benutzt.</h1>
        <p>Termine, Erinnerungen und Zuständigkeiten an einem Ort – statt in drei Chat-Gruppen und dem Kopf von Mama.</p>
      </div>
      <p class="auth-panel-footer">Family-Kalender</p>
    </aside>
    <main class="auth-content">
      <?= brandLogo('', 'login.php') ?>
      <div class="login-card">
        <p class="eyebrow">Sicher anmelden</p>
        <h1>Willkommen zurück</h1>
        <p class="hint-text card-intro">Melde dich an, um deinen Kalender zu öffnen.</p>

        <form method="post" action="login_submit.php">
          <?= csrfField() ?>
          <label>E-Mail</label>
          <input type="email" name="email" required autofocus />

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
      <footer class="auth-footer">
        <a href="impressum.php">Impressum</a><span class="sep">·</span><a href="datenschutz.php">Datenschutz</a>
      </footer>
    </main>
  </div>
</body>
</html>
