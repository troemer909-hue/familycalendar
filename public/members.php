<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();
$membersRes = $client->request('GET', '/api/members');
handleUnauthorized($membersRes);
$members = $membersRes['ok'] ? $membersRes['data'] : [];
$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienmitglieder</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="main-area">
  <div class="form-card" style="max-width:560px;">
    <h1>Familienmitglieder</h1>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>

    <div class="members-list">
      <?php foreach ($members as $m): ?>
        <div class="member-row">
          <span class="dot" style="background:<?= h($m['display_color']) ?>"></span>
          <span class="name"><?= h($m['display_name']) ?></span>
          <?php if ($m['role'] === 'admin'): ?><span class="badge admin">Admin</span><?php endif; ?>
          <?php if ($m['member_type'] === 'login'): ?>
            <span class="badge login">Login</span>
          <?php else: ?>
            <span class="badge profile">Profil</span>
          <?php endif; ?>

          <?php if ($m['member_type'] === 'profile'): ?>
            <a class="btn btn-secondary" href="member_upgrade_form.php?id=<?= (int) $m['id'] ?>">Zu Login hochstufen</a>
          <?php elseif ($m['role'] !== 'admin'): ?>
            <form method="post" action="member_downgrade.php" style="margin:0;">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>" />
              <button type="submit" class="btn btn-secondary">Login entziehen</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <h2 style="font-size:16px;">Neues Profil ohne Login (z. B. für ein Kind)</h2>
    <form method="post" action="member_add.php">
      <?= csrfField() ?>
      <div class="row">
        <div>
          <label>Name</label>
          <input type="text" name="display_name" required />
        </div>
        <div>
          <label>Farbe</label>
          <input type="text" name="display_color" value="#94a3b8" placeholder="#94a3b8" />
        </div>
        <div>
          <label>Geburtsjahr (optional)</label>
          <input type="number" name="birth_year" min="1900" max="2100" />
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Profil anlegen</button>
        <div class="spacer"></div>
        <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
