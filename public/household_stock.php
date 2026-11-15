<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$itemsRes = $client->request('GET', '/api/household-stock');
handleUnauthorized($itemsRes);
$items = $itemsRes['ok'] ? $itemsRes['data'] : [];

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Vorratsliste</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('shopping') ?>
  <div class="form-card" style="max-width:560px;">
    <h1>Vorratsliste</h1>
    <p class="hint-text">
      Dauerhafte Liste wiederkehrender Haushaltsartikel (z.B. Toilettenpapier,
      Spülmaschinentabs). "Wird knapp" markiert den Artikel und trägt ihn
      automatisch in die <a href="shopping_list.php">Einkaufsliste</a> ein -
      wird beim Abhaken dort automatisch wieder zurückgesetzt.
    </p>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="error-box" style="background:#dcfce7;color:#166534;"><?= h($notice) ?></p><?php endif; ?>

    <?php if (empty($items)): ?>
      <p class="hint-text">Noch keine Vorratsartikel angelegt.</p>
    <?php else: ?>
      <div class="members-list">
        <?php foreach ($items as $i): ?>
          <div class="member-row" style="align-items:flex-start;flex-wrap:wrap;">
            <div style="flex:1;min-width:160px;">
              <span><?= h($i['label']) ?></span>
              <?php if ($i['low_stock']): ?>
                <br /><span class="hint-text">wird knapp - steht auf der Einkaufsliste</span>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;">
              <?php if ($i['low_stock']): ?>
                <button type="button" class="btn btn-secondary" disabled>Schon gemeldet</button>
              <?php else: ?>
                <form method="post" action="household_stock_flag_low.php" style="margin:0;">
                  <?= csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int) $i['id'] ?>" />
                  <button type="submit" class="btn btn-primary">Wird knapp</button>
                </form>
              <?php endif; ?>
              <form method="post" action="household_stock_delete.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>" />
                <button type="submit" class="btn btn-danger">Entfernen</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 style="font-size:16px;margin-top:28px;">Artikel zur Vorratsliste hinzufügen</h2>
    <form method="post" action="household_stock_add.php">
      <?= csrfField() ?>
      <div class="row" style="align-items:center;gap:8px;">
        <input type="text" name="label" placeholder="z.B. Toilettenpapier" required style="flex:1;" />
        <button type="submit" class="btn btn-primary">Hinzufügen</button>
      </div>
    </form>

    <div class="form-actions" style="margin-top:28px;">
      <a href="shopping_list.php" class="btn btn-secondary">Zurück zur Einkaufsliste</a>
    </div>
  </div>
<?= appChromeClose('shopping') ?>
</body>
</html>
