<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$plansRes = $client->request('GET', '/api/admin/plans');
handleUnauthorized($plansRes);
handleForbidden($plansRes);
$plans = $plansRes['ok'] ? $plansRes['data'] : [];
$plansError = $plansRes['ok'] ? null : ($plansRes['data']['error'] ?? 'Pläne konnten nicht geladen werden.');

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Plattform-Verwaltung – Pläne</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('admin') ?>
  <div class="form-card" style="max-width:900px;">
    <h1>Pläne</h1>
    <p class="hint-text">
      SaaS-Pakete, die Familien zugeordnet werden können (siehe
      <a href="admin_families.php">Familien verwalten</a>). Reine
      Paket-Definitionen - die Zahlungsabwicklung selbst ist bewusst kein
      Teil dieser App.
    </p>

    <?php if ($notice): ?><p class="success-box"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($plansError): ?><p class="error-box"><?= h($plansError) ?></p><?php endif; ?>

    <div class="table-scroll">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Code</th>
          <th>Name</th>
          <th>Preis/Monat</th>
          <th>Max. Mitglieder</th>
          <th>Max. Kalender-Abos</th>
          <th>Familien</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($plans as $p): ?>
          <tr>
            <td><code><?= h($p['code']) ?></code></td>
            <td><?= h($p['name']) ?></td>
            <td><?= number_format((float) $p['price_monthly'], 2, ',', '.') ?> €</td>
            <td><?= (int) $p['max_members'] ?></td>
            <td><?= (int) $p['max_calendar_streams'] ?></td>
            <td><?= (int) $p['family_count'] ?></td>
            <td style="white-space:nowrap;">
              <a href="admin_plan_form.php?id=<?= (int) $p['id'] ?>" class="btn btn-secondary">Bearbeiten</a>
              <form method="post" action="admin_plan_delete.php" style="display:inline;margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>" />
                <button type="submit" class="btn btn-danger" <?= $p['family_count'] > 0 ? 'disabled title="Wird noch von Familien genutzt"' : '' ?>>Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($plans)): ?>
          <tr><td colspan="7" class="hint-text">Noch keine Pläne angelegt.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>

    <p style="margin-top:20px;">
      <a href="admin_plan_form.php" class="btn btn-primary">+ Neuer Plan</a>
      <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
    </p>
  </div>
<?= appChromeClose('admin') ?>
</body>
</html>
