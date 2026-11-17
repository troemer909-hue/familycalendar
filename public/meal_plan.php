<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
$client = currentApiClient();
$anchor = $_GET['week'] ?? date('Y-m-d');
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor) ?: new DateTimeImmutable('today');
$monday = $dt->modify('monday this week');
$days = [];
for ($i=0; $i<7; $i++) $days[] = $monday->modify("+{$i} days");
$from = $days[0]->format('Y-m-d'); $to = $days[6]->format('Y-m-d');
$res = $client->request('GET', '/api/meal-plan?from='.$from.'&to='.$to);
handleUnauthorized($res); $entries = $res['ok'] ? $res['data'] : [];
$byCell=[]; foreach ($entries as $e) $byCell[$e['entry_date']][$e['meal_slot']]=$e;
$slots=['breakfast'=>'Frühstück','lunch'=>'Mittagessen','dinner'=>'Abendessen','snack'=>'Snack'];
$error=$_GET['error']??null; $notice=$_GET['notice']??null;
?>
<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"/><title>Essensplan</title><link rel="stylesheet" href="styles.css"/><?= pwaHeadTags() ?>
<style>.meal-grid{display:grid;grid-template-columns:repeat(8,minmax(105px,1fr));overflow-x:auto}.meal-grid>div{padding:8px;border:1px solid #e5e7eb}.meal-cell{min-height:110px}.meal-cell input,.meal-cell textarea{width:100%;box-sizing:border-box;margin:3px 0}.meal-day{font-weight:bold;background:#f8fafc}.meal-actions{display:flex;gap:5px;flex-wrap:wrap}.meal-actions button{padding:5px}</style></head><body>
<?= appChromeOpen('meal-plan') ?>
<div class="form-card" style="max-width:1200px"><h1>Essensplan</h1><p class="hint-text">Wochenplan für gemeinsame Mahlzeiten. Zutaten für die Einkaufsliste trägst du selbst ein.</p>
<?php if($error): ?><p class="error-box"><?=h($error)?></p><?php endif; ?><?php if($notice): ?><p class="error-box" style="background:#dcfce7;color:#166534;"><?=h($notice)?></p><?php endif; ?>
<div class="row" style="justify-content:space-between;align-items:center;margin:16px 0"><a class="btn btn-secondary" href="?week=<?=$monday->modify('-7 days')->format('Y-m-d')?>">← Vorige Woche</a><strong><?=h($monday->format('d.m.'))?> – <?=h($days[6]->format('d.m.Y'))?></strong><a class="btn btn-secondary" href="?week=<?=$monday->modify('+7 days')->format('Y-m-d')?>">Nächste Woche →</a></div>
<div class="meal-grid"><div></div><?php foreach($days as $day): ?><div class="meal-day"><?=h(['Mo','Di','Mi','Do','Fr','Sa','So'][(int)$day->format('N')-1])?> <?=h($day->format('d.m.'))?></div><?php endforeach; ?>
<?php foreach($slots as $slot=>$slotName): ?><div class="meal-day"><?=h($slotName)?></div><?php foreach($days as $day): $date=$day->format('Y-m-d'); $entry=$byCell[$date][$slot]??null; ?><div class="meal-cell"><form method="post" action="meal_plan_save.php"><?=csrfField()?><?php if($entry): ?><input type="hidden" name="id" value="<?=(int)$entry['id']?>"/><?php endif; ?><input type="hidden" name="entry_date" value="<?=h($date)?>"/><input type="hidden" name="meal_slot" value="<?=h($slot)?>"/><input name="label" maxlength="255" placeholder="Gericht" value="<?=h($entry['label']??'')?>" required/><textarea name="notes" rows="2" placeholder="Notizen"><?=h($entry['notes']??'')?></textarea><button class="btn btn-primary" type="submit"><?=$entry?'Speichern':'Eintragen'?></button></form>
<?php if($entry): ?><form method="post" action="meal_plan_shopping.php" style="margin-top:5px"><?=csrfField()?><input type="hidden" name="id" value="<?=(int)$entry['id']?>"/><input name="label" maxlength="255" placeholder="Fehlende Zutat" required/><button class="btn btn-secondary" type="submit">Zutat fehlt?</button></form><form method="post" action="meal_plan_delete.php"><?=csrfField()?><input type="hidden" name="id" value="<?=(int)$entry['id']?>"/><button class="btn btn-danger" type="submit">Löschen</button></form><?php endif; ?></div><?php endforeach; ?><?php endforeach; ?></div></div>
<?=appChromeClose('meal-plan')?></body></html>
