<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$listsRes = $client->request('GET', '/api/task-lists');
handleUnauthorized($listsRes);
$lists = $listsRes['ok'] ? $listsRes['data'] : [];

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Aufgabenlisten</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('tasks') ?>
  <div class="form-card" style="max-width:640px;">
    <h1>Aufgabenlisten</h1>
    <p class="hint-text">
      Geteilte Listen für die ganze Familie - z.B. Einkaufsliste, Haushalt
      oder Packliste. Jede Aufgabe kann optional einer Person zugewiesen
      werden.
    </p>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="error-box" style="background:#dcfce7;color:#166534;"><?= h($notice) ?></p><?php endif; ?>

    <?php if (empty($lists)): ?>
      <p class="hint-text">Noch keine Aufgabenliste angelegt.</p>
    <?php else: ?>
      <div class="members-list">
        <?php foreach ($lists as $l): ?>
          <a href="task_list_view.php?id=<?= (int) $l['id'] ?>" class="member-row" style="text-decoration:none;color:inherit;">
            <span class="name"><strong><?= h($l['name']) ?></strong></span>
            <span class="hint-text">
              <?= (int) $l['open_count'] ?> offen · <?= (int) $l['total_count'] ?> gesamt
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 style="font-size:16px;margin-top:28px;">Neue Liste anlegen</h2>
    <form method="post" action="task_list_add.php">
      <?= csrfField() ?>
      <label>Name der Liste</label>
      <input type="text" name="name" placeholder="z.B. Einkaufsliste" required />
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Liste anlegen</button>
        <div class="spacer"></div>
        <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
      </div>
    </form>
  </div>
<?= appChromeClose('tasks') ?>
</body>
</html>
