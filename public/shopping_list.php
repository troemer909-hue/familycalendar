<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$itemsRes = $client->request('GET', '/api/shopping-list');
handleUnauthorized($itemsRes);
$items = $itemsRes['ok'] ? $itemsRes['data'] : [];

$openItems = array_values(array_filter($items, static fn (array $i): bool => !$i['checked']));
$checkedItems = array_values(array_filter($items, static fn (array $i): bool => $i['checked']));

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Einkaufsliste</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('shopping') ?>
  <div class="form-card" style="max-width:560px;">
    <h1>Einkaufsliste</h1>
    <p class="hint-text">
      Eine gemeinsame Liste für die ganze Familie - jeder kann Artikel
      hinzufügen und beim Einkaufen direkt abhaken.
    </p>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="error-box" style="background:#dcfce7;color:#166534;"><?= h($notice) ?></p><?php endif; ?>

    <form method="post" action="shopping_list_add.php" style="margin-bottom:20px;">
      <?= csrfField() ?>
      <div class="row" style="align-items:center;gap:8px;">
        <input type="text" name="label" placeholder="z.B. Milch, 2x" required style="flex:1;" />
        <button type="submit" class="btn btn-primary">Hinzufügen</button>
      </div>
    </form>

    <?php if (empty($openItems)): ?>
      <p class="hint-text">Keine offenen Artikel.</p>
    <?php else: ?>
      <div class="members-list">
        <?php foreach ($openItems as $i): ?>
          <div class="member-row" style="align-items:flex-start;flex-wrap:wrap;">
            <div style="flex:1;min-width:160px;">
              <span><?= h($i['label']) ?></span><br />
              <?php if ($i['added_by_name']): ?>
                <span class="hint-text">hinzugefügt von <?= h($i['added_by_name']) ?></span>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;">
              <form method="post" action="shopping_list_toggle.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>" />
                <input type="hidden" name="checked" value="1" />
                <button type="submit" class="btn btn-secondary">Abhaken</button>
              </form>
              <form method="post" action="shopping_list_delete.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>" />
                <button type="submit" class="btn btn-danger">Entfernen</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($checkedItems)): ?>
      <h2 style="font-size:16px;margin-top:28px;">Schon im Wagen</h2>
      <div class="members-list">
        <?php foreach ($checkedItems as $i): ?>
          <div class="member-row" style="align-items:flex-start;flex-wrap:wrap;opacity:.6;">
            <div style="flex:1;min-width:160px;">
              <span style="text-decoration:line-through;"><?= h($i['label']) ?></span><br />
              <?php if ($i['checked_by_name']): ?>
                <span class="hint-text">abgehakt von <?= h($i['checked_by_name']) ?></span>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;">
              <form method="post" action="shopping_list_toggle.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>" />
                <input type="hidden" name="checked" value="0" />
                <button type="submit" class="btn btn-secondary">Zurücksetzen</button>
              </form>
              <form method="post" action="shopping_list_delete.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>" />
                <button type="submit" class="btn btn-danger">Entfernen</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" action="shopping_list_clear_checked.php" style="margin-top:16px;">
        <?= csrfField() ?>
        <button type="submit" class="btn btn-secondary">Liste leeren (alle abgehakten entfernen)</button>
      </form>
    <?php endif; ?>

    <div class="form-actions" style="margin-top:28px;">
      <a href="household_stock.php" class="btn btn-secondary">Vorratsliste verwalten</a>
      <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
    </div>
  </div>
<?= appChromeClose('shopping') ?>
</body>
</html>
