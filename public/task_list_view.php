<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$listId = (int) ($_GET['id'] ?? 0);
if ($listId <= 0) {
    header('Location: task_lists.php');
    exit;
}

$client = currentApiClient();

$listsRes = $client->request('GET', '/api/task-lists');
handleUnauthorized($listsRes);
$list = null;
foreach (($listsRes['ok'] ? $listsRes['data'] : []) as $l) {
    if ((int) $l['id'] === $listId) {
        $list = $l;
        break;
    }
}
if ($list === null) {
    header('Location: task_lists.php?error=' . urlencode('Liste nicht gefunden.'));
    exit;
}

$tasksRes = $client->request('GET', "/api/task-lists/{$listId}/tasks");
handleUnauthorized($tasksRes);
$tasks = $tasksRes['ok'] ? $tasksRes['data'] : [];

$membersRes = $client->request('GET', '/api/members');
handleUnauthorized($membersRes);
$members = $membersRes['ok'] ? $membersRes['data'] : [];

$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title><?= h($list['name']) ?> - Aufgabenliste</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('tasks') ?>
  <div class="form-card" style="max-width:640px;">
    <h1><?= h($list['name']) ?></h1>
    <?php if ($list['assigned_member_name']): ?>
      <p><span class="badge profile">Für <?= h($list['assigned_member_name']) ?></span></p>
    <?php endif; ?>
    <p><a href="task_lists.php" class="hint-text">&larr; Alle Listen</a></p>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>

    <?php if (empty($tasks)): ?>
      <p class="hint-text">Noch keine Aufgabe in dieser Liste.</p>
    <?php else: ?>
      <div class="members-list">
        <?php foreach ($tasks as $t): ?>
          <div class="member-row" style="align-items:flex-start;flex-wrap:wrap;<?= $t['is_done'] ? 'opacity:.6;' : '' ?>">
            <div style="flex:1;min-width:180px;">
              <span style="<?= $t['is_done'] ? 'text-decoration:line-through;' : '' ?>"><?= h($t['title']) ?></span><br />
              <span class="hint-text">
                <?php if ($t['assigned_member_name']): ?><?= h($t['assigned_member_name']) ?><?php endif; ?>
                <?php if ($t['assigned_member_name'] && $t['due_date']): ?> · <?php endif; ?>
                <?php if ($t['due_date']): ?>fällig <?= h(formatGermanDate($t['due_date'])) ?><?php endif; ?>
              </span>
            </div>
            <div style="display:flex;gap:8px;">
              <form method="post" action="task_toggle.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>" />
                <input type="hidden" name="list_id" value="<?= $listId ?>" />
                <input type="hidden" name="is_done" value="<?= $t['is_done'] ? '0' : '1' ?>" />
                <button type="submit" class="btn btn-secondary"><?= $t['is_done'] ? 'Zurücksetzen' : 'Erledigt' ?></button>
              </form>
              <form method="post" action="task_delete.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>" />
                <input type="hidden" name="list_id" value="<?= $listId ?>" />
                <button type="submit" class="btn btn-danger">Entfernen</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 style="font-size:16px;margin-top:28px;">Aufgabe hinzufügen</h2>
    <form method="post" action="task_add.php">
      <?= csrfField() ?>
      <input type="hidden" name="list_id" value="<?= $listId ?>" />
      <label>Titel</label>
      <input type="text" name="title" placeholder="z.B. Milch kaufen" required />
      <label>Zuweisen (optional)</label>
      <select name="assigned_member_id">
        <option value="">Niemand</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= h($m['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Fällig am (optional, Pflicht bei Wiederholung)</label>
      <input type="date" name="due_date" />
      <label>Wiederholung (optional)</label>
      <select name="repeat_freq">
        <option value="none">Keine Wiederholung</option>
        <option value="daily">Täglich</option>
        <option value="weekly">Wöchentlich</option>
        <option value="biweekly">Alle 2 Wochen</option>
        <option value="monthly">Monatlich</option>
        <option value="yearly">Jährlich</option>
      </select>
      <div class="row" style="margin-top:6px;align-items:center;">
        <label class="checkbox-label"><input type="radio" name="repeat_end_type" value="count" checked /> Anzahl Wiederholungen</label>
        <input type="number" name="repeat_count" value="8" min="1" max="366" />
      </div>
      <div class="row" style="align-items:center;">
        <label class="checkbox-label"><input type="radio" name="repeat_end_type" value="until" /> Enddatum</label>
        <input type="date" name="repeat_until" />
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Hinzufügen</button>
      </div>
    </form>

    <form method="post" action="task_list_delete.php" style="margin-top:28px;">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= $listId ?>" />
      <button type="submit" class="btn btn-danger">Liste löschen (inkl. aller Aufgaben)</button>
    </form>
  </div>
<?= appChromeClose('tasks') ?>
</body>
</html>
