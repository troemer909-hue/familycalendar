<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Zu Login hochstufen</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="main-area">
  <div class="form-card">
    <h1>Zu Login hochstufen</h1>
    <p class="hint-text">Die Person bekommt damit ein eigenes Login, behält aber alle bisherigen Termine und ihre Farbe.</p>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="member_upgrade.php">
      <input type="hidden" name="id" value="<?= $id ?>" />
      <label>E-Mail</label>
      <input type="email" name="email" required />
      <label>Passwort (min. 8 Zeichen)</label>
      <input type="password" name="password" minlength="8" required />

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Hochstufen</button>
        <a href="members.php" class="btn btn-secondary">Abbrechen</a>
        <div class="spacer"></div>
      </div>
    </form>
  </div>
</div>
</body>
</html>
