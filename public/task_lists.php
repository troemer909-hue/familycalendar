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

// Aufgaben jeder Liste gleich mitladen (ein Request pro Liste - bei der
// überschaubaren Anzahl Listen einer Familie unkritisch), damit alles auf
// dieser einen Seite abgehakt/angelegt/gelöscht werden kann, ohne erst in
// eine einzelne Liste wechseln zu müssen. Vorher lag das in
// task_list_view.php, eine Klick-Ebene tiefer - wurde so nicht gefunden.
$tasksByList = [];
$poolByList = [];
$rhythmLabels = ['daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'monthly' => 'Monatlich'];
foreach ($lists as $l) {
    $tasksRes = $client->request('GET', "/api/task-lists/{$l['id']}/tasks");
    $tasksByList[$l['id']] = $tasksRes['ok'] ? $tasksRes['data'] : [];

    $poolRes = $client->request('GET', "/api/task-lists/{$l['id']}/assignment-pool");
    $poolByList[$l['id']] = $poolRes['ok'] ? $poolRes['data'] : null;
}

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
// Nach einer Aktion (Aufgabe anlegen/abhaken/löschen) bleibt die
// betroffene Liste aufgeklappt, siehe task_add.php/task_toggle.php/
// task_delete.php, die hierher statt zu task_list_view.php zurückleiten.
$openListId = (int) ($_GET['open'] ?? 0);
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
      <?php foreach ($lists as $l): $listId = (int) $l['id']; $tasks = $tasksByList[$listId]; $pool = $poolByList[$listId]; ?>
        <details id="list-<?= $listId ?>" class="task-list-details"<?= $openListId === $listId ? ' open' : '' ?>>
          <summary>
            <strong><?= h($l['name']) ?></strong>
            <?php if ($l['assigned_member_name']): ?>
              <span class="badge profile">Für <?= h($l['assigned_member_name']) ?></span>
            <?php endif; ?>
            <span class="hint-text"><?= (int) $l['open_count'] ?> offen · <?= (int) $l['total_count'] ?> gesamt</span>
          </summary>

          <div style="padding:12px 2px 4px;">
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
                        <?php if ($pool && $pool['active'] && $t['auto_assign']): ?>
                          <?php if ($t['assigned_member_name']): ?>
                            · 🎲 verlost
                          <?php else: ?>
                            <?php /* Kann sowohl heißen "noch keine Ziehung gelaufen" als auch
                                      "bei der letzten Ziehung mangels Kapazität nicht zugeteilt" -
                                      beides lässt sich ohne zusätzliche Historie nicht
                                      unterscheiden, deshalb bewusst neutral formuliert statt
                                      "Kapazität voll" zu behaupten, wenn eventuell einfach
                                      noch nie gezogen wurde. */ ?>
                            · 🎲 noch nicht zugeteilt
                          <?php endif; ?>
                        <?php endif; ?>
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
                      <?php if ($pool): ?>
                        <form method="post" action="task_auto_assign_toggle.php" style="margin:0;">
                          <?= csrfField() ?>
                          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>" />
                          <input type="hidden" name="list_id" value="<?= $listId ?>" />
                          <input type="hidden" name="auto_assign" value="<?= $t['auto_assign'] ? '0' : '1' ?>" />
                          <button type="submit" class="btn btn-secondary" title="Aus der Zufalls-Zuteilung herausnehmen bzw. wieder aufnehmen"><?= $t['auto_assign'] ? 'Aus Losliste' : 'In Losliste' ?></button>
                        </form>
                      <?php endif; ?>
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

            <details style="margin-top:16px;">
              <summary class="btn btn-secondary" style="display:inline-block;cursor:pointer;">Aufgabe hinzufügen</summary>
              <form method="post" action="task_add.php" style="margin-top:10px;">
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
            </details>

            <details style="margin-top:10px;">
              <summary class="btn btn-secondary" style="display:inline-block;cursor:pointer;">Zufalls-Zuteilung <?= $pool ? 'bearbeiten' : 'einrichten' ?></summary>
              <div style="margin-top:10px;">
                <p class="hint-text">
                  Verlost jede Aufgabe dieser Liste im gewählten Rhythmus unabhängig neu an eine Person aus dem Kreis unten - keine manuelle Zuweisung mehr nötig, auch bei mehreren Kindern nicht fair verhandelbar.
                  <?php if ($pool): ?>
                    Nächste Ziehung: <?= h(formatGermanDate($pool['next_draw_at'])) ?><?= $pool['active'] ? '' : ' (Automatik aktuell deaktiviert)' ?>.
                  <?php endif; ?>
                </p>
                <form method="post" action="task_assignment_pool_save.php">
                  <?= csrfField() ?>
                  <input type="hidden" name="list_id" value="<?= $listId ?>" />
                  <label>Personen im Los-Kreis (mit optionaler Obergrenze pro Ziehung)</label>
                  <?php foreach ($members as $m):
                    $existingMember = null;
                    foreach (($pool['members'] ?? []) as $pm) {
                        if ($pm['family_member_id'] === (int) $m['id']) {
                            $existingMember = $pm;
                            break;
                        }
                    }
                  ?>
                    <div class="row" style="align-items:center;gap:10px;">
                      <label class="checkbox-label" style="flex:1;">
                        <input type="checkbox" name="member_ids[]" value="<?= (int) $m['id'] ?>"<?= $existingMember ? ' checked' : '' ?> />
                        <?= h($m['display_name']) ?>
                      </label>
                      <input type="number" name="max_tasks[<?= (int) $m['id'] ?>]" min="1" placeholder="unbegrenzt" style="width:120px;" value="<?= $existingMember && $existingMember['max_tasks'] !== null ? (int) $existingMember['max_tasks'] : '' ?>" />
                    </div>
                  <?php endforeach; ?>
                  <label>Rhythmus</label>
                  <select name="rhythm">
                    <?php foreach ($rhythmLabels as $value => $label): ?>
                      <option value="<?= $value ?>"<?= ($pool['rhythm'] ?? 'weekly') === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label>Modus</label>
                  <div class="row" style="align-items:center;">
                    <label class="checkbox-label"><input type="radio" name="mode" value="fair"<?= ($pool['mode'] ?? 'fair') === 'fair' ? ' checked' : '' ?> /> Fair (wer dran war, ist bei derselben Aufgabe nicht sofort wieder dran)</label>
                  </div>
                  <div class="row" style="align-items:center;">
                    <label class="checkbox-label"><input type="radio" name="mode" value="random"<?= ($pool['mode'] ?? '') === 'random' ? ' checked' : '' ?> /> Zufall (auch mehrfach hintereinander möglich)</label>
                  </div>
                  <label class="checkbox-label" style="margin-top:10px;">
                    <input type="checkbox" name="active" value="1"<?= ($pool['active'] ?? true) ? ' checked' : '' ?> /> Automatik aktiv
                  </label>
                  <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Speichern</button>
                  </div>
                </form>
                <?php if ($pool): ?>
                  <form method="post" action="task_assignment_pool_delete.php" style="margin-top:10px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="list_id" value="<?= $listId ?>" />
                    <button type="submit" class="btn btn-danger">Zufalls-Zuteilung entfernen</button>
                  </form>
                <?php endif; ?>
              </div>
            </details>

            <form method="post" action="task_list_delete.php" style="margin-top:16px;">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= $listId ?>" />
              <button type="submit" class="btn btn-danger">Liste löschen (inkl. aller Aufgaben)</button>
            </form>
          </div>
        </details>
      <?php endforeach; ?>
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
