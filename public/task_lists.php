<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$listsRes = $client->request('GET', '/api/task-lists');
handleUnauthorized($listsRes);
$lists = $listsRes['ok'] ? $listsRes['data'] : [];

$membersRes = $client->request('GET', '/api/members');
handleUnauthorized($membersRes);
$members = $membersRes['ok'] ? $membersRes['data'] : [];

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
            <span class="name">
              <strong><?= h($l['name']) ?></strong>
              <?php if ($l['assigned_member_name']): ?>
                <span class="badge profile">Für <?= h($l['assigned_member_name']) ?></span>
              <?php endif; ?>
            </span>
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
      <label>Für eine bestimmte Person (optional)</label>
      <select name="assigned_member_id">
        <option value="">Niemand bestimmtes (für alle)</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= h($m['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint-text">
        Die Liste bleibt für die ganze Familie sichtbar - die zugewiesene
        Person bekommt zusätzlich eine Push-Benachrichtigung (falls sie ein
        eigenes Login mit aktivierten Push-Benachrichtigungen hat).
      </p>
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
