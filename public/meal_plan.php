<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
$client = currentApiClient();
$start = $_GET['week'] ?? date('Y-m-d');
$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: new DateTimeImmutable('today');
$monday = $parsed->modify('monday this week');
$sunday = $monday->modify('+6 days');
$from = $monday->format('Y-m-d'); $to = $sunday->format('Y-m-d');
$res = $client->request('GET', '/api/meal-plan?from=' . $from . '&to=' . $to);
handleUnauthorized($res); $entries = $res['ok'] ? $res['data'] : [];
$error = $_GET['error'] ?? null;
$byCell = [];
foreach ($entries as $entry) $byCell[$entry['entry_date']][$entry['meal_slot']] = $entry;
$slots = ['breakfast' => 'Frühstück', 'lunch' => 'Mittagessen', 'dinner' => 'Abendessen', 'snack' => 'Snack'];
$prev = $monday->modify('-7 days')->format('Y-m-d'); $next = $monday->modify('+7 days')->format('Y-m-d');
?>
<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><title>Essensplan</title><link rel="stylesheet" href="styles.css"/><?= pwaHeadTags() ?></head><body>
<?= appChromeOpen('household') ?>
<div class="form-card" style="max-width:1100px;width:100%;">
  <h1>Essensplan</h1>
  <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
  <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:16px"><a class="btn btn-secondary" href="?week=<?= h($prev) ?>">← Vorige Woche</a><strong><?= h($monday->format('d.m.Y')) ?> – <?= h($sunday->format('d.m.Y')) ?></strong><a class="btn btn-secondary" href="?week=<?= h($next) ?>">Nächste Woche →</a></div>
  <div style="overflow-x:auto"><table class="meal-plan-grid"><thead><tr><th>Mahlzeit</th><?php for($i=0;$i<7;$i++):$day=$monday->modify("+$i days"); ?><th><?= h(['Mo','Di','Mi','Do','Fr','Sa','So'][$i]) ?><br><?= h($day->format('d.m.')) ?></th><?php endfor ?></tr></thead><tbody>
  <?php foreach($slots as $slot=>$slotName): ?><tr><th><?= h($slotName) ?></th><?php for($i=0;$i<7;$i++):$date=$monday->modify("+$i days")->format('Y-m-d');$entry=$byCell[$date][$slot]??null; ?><td>
    <?php if($entry): ?><strong><?= h($entry['label']) ?></strong><?php if($entry['notes']): ?><div class="hint-text"><?= nl2br(h($entry['notes'])) ?></div><?php endif ?>
      <details><summary style="cursor:pointer">Bearbeiten</summary><form method="post" action="meal_plan_save.php"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$entry['id'] ?>"/><input type="hidden" name="week" value="<?= h($from) ?>"/><input type="hidden" name="entry_date" value="<?= h($date) ?>"/><input type="hidden" name="meal_slot" value="<?= h($slot) ?>"/><input name="label" maxlength="255" required value="<?= h($entry['label']) ?>"/><textarea name="notes" placeholder="Notizen"><?= h($entry['notes']??'') ?></textarea><button class="btn btn-primary">Speichern</button></form>
      <form method="post" action="meal_plan_delete.php"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$entry['id'] ?>"/><input type="hidden" name="week" value="<?= h($from) ?>"/><button class="btn btn-danger">Löschen</button></form></details>
      <form method="post" action="meal_plan_shopping_add.php" style="margin-top:8px"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$entry['id'] ?>"/><input type="hidden" name="week" value="<?= h($from) ?>"/><input name="label" maxlength="255" placeholder="Fehlende Zutat" required/><button class="btn btn-secondary">Zur Einkaufsliste</button></form>
    <?php else: ?><details><summary style="cursor:pointer">+ Eintragen</summary><form method="post" action="meal_plan_save.php"><?= csrfField() ?><input type="hidden" name="week" value="<?= h($from) ?>"/><input type="hidden" name="entry_date" value="<?= h($date) ?>"/><input type="hidden" name="meal_slot" value="<?= h($slot) ?>"/><input name="label" maxlength="255" placeholder="Gericht" required/><textarea name="notes" placeholder="Notizen"></textarea><button class="btn btn-primary">Speichern</button></form></details><?php endif ?>
  </td><?php endfor ?></tr><?php endforeach ?>
  </tbody></table></div>
</div>
<?= appChromeClose('household') ?></body></html>
