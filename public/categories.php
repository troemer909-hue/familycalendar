<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$res = $client->request('GET', '/api/categories');
handleUnauthorized($res);
$editable = $res['ok'] ? (bool) ($res['data']['editable'] ?? false) : false;
$categories = $res['ok'] ? ($res['data']['categories'] ?? []) : [];

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Kategorien</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('categories') ?>
  <div class="form-card" style="max-width:560px;">
    <h1>Kategorien</h1>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="success-box"><?= h($notice) ?></p><?php endif; ?>

    <?php if (!$editable): ?>
      <p class="hint-text">
        Diese feste Auswahlliste steht in der Free-Version zur Verfügung
        und erscheint beim Anlegen eines Termins. Mit <strong>Plus</strong>
        kannst du eigene Kategorien mit frei wählbaren Namen und Farben
        anlegen, umbenennen und löschen.
      </p>
    <?php else: ?>
      <p class="hint-text">
        Eigene Kategorien für deine Familie - erscheinen beim Anlegen eines
        Termins zur Auswahl.
      </p>
    <?php endif; ?>

    <div class="members-list">
      <?php foreach ($categories as $c): ?>
        <div class="member-row">
          <span class="dot" style="background:<?= h($c['color']) ?>"></span>
          <span class="name"><?= h($c['name']) ?></span>
          <?php if ($editable): ?>
            <form method="post" action="category_update.php" style="margin:0;display:flex;gap:6px;align-items:center;">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>" />
              <input type="color" name="color" value="<?= h($c['color']) ?>" class="color-input" style="width:34px;height:34px;" />
              <input type="text" name="name" value="<?= h($c['name']) ?>" style="width:120px;" maxlength="50" required />
              <button type="submit" class="btn btn-secondary">Speichern</button>
            </form>
            <form method="post" action="category_delete.php" style="margin:0;">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>" />
              <button type="submit" class="btn btn-danger">Löschen</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($editable): ?>
      <h2 style="font-size:16px;margin-top:28px;">Neue Kategorie</h2>
      <form method="post" action="category_add.php">
        <?= csrfField() ?>
        <label>Name</label>
        <input type="text" name="name" maxlength="50" placeholder="z.B. Musikschule" required />
        <label>Farbe</label>
        <input type="color" name="color" value="#64748b" class="color-input" />
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Anlegen</button>
          <div class="spacer"></div>
          <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
        </div>
      </form>
    <?php else: ?>
      <p style="margin-top:20px;">
        <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
      </p>
    <?php endif; ?>
  </div>
<?= appChromeClose('categories') ?>
</body>
</html>
