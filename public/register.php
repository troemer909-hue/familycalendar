<?php
require_once __DIR__ . '/../src/bootstrap.php';
$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender – Registrierung</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
  <div class="auth-layout">
    <aside class="auth-panel">
      <?= brandLogo('', 'login.php') ?>
      <div class="auth-panel-copy">
        <p class="eyebrow">Jetzt starten</p>
        <h1>In Minuten eingerichtet, keine Kreditkarte nötig.</h1>
        <p>Lege eure Familie an und lade danach alle anderen Mitglieder einfach per E-Mail ein.</p>
      </div>
      <p class="auth-panel-footer">Family-Kalender</p>
    </aside>
    <main class="auth-content">
      <?= brandLogo('', 'login.php') ?>
      <div class="login-card">
        <p class="eyebrow">Kostenlos starten</p>
        <h1>Neue Familie anlegen</h1>
        <p class="hint-text card-intro">Du wirst automatisch Admin eurer neuen Familie.</p>

        <form method="post" action="register_submit.php">
          <?= csrfField() ?>
          <label>Familienname</label>
          <input type="text" name="family_name" placeholder="Familie Muster" required autofocus />

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
      <footer class="auth-footer">
        <a href="impressum.php">Impressum</a><span class="sep">·</span><a href="datenschutz.php">Datenschutz</a>
      </footer>
    </main>
  </div>
</body>
</html>
