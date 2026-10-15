<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$planId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$error = $_GET['error'] ?? null;

$plan = null;
if ($planId) {
    $res = $client->request('GET', "/api/admin/plans/{$planId}");
    handleUnauthorized($res);
    handleForbidden($res);
    if (!$res['ok']) {
        header('Location: admin_plans.php?error=' . urlencode($res['data']['error'] ?? 'Plan nicht gefunden.'));
        exit;
    }
    $plan = $res['data'];
}

$codeValue = $plan['code'] ?? ($_GET['code'] ?? '');
$nameValue = $plan['name'] ?? ($_GET['name'] ?? '');
$maxMembersValue = $plan['max_members'] ?? 5;
$maxStreamsValue = $plan['max_calendar_streams'] ?? 0;
$priceValue = $plan['price_monthly'] ?? '0.00';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Plattform-Verwaltung – Plan <?= $plan ? 'bearbeiten' : 'anlegen' ?></title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="main-area">
  <div class="form-card">
    <h1><?= $plan ? 'Plan bearbeiten' : 'Neuer Plan' ?></h1>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="admin_plan_save.php">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= $planId ? (int) $planId : '' ?>" />

      <label>Code (eindeutig, z.B. "standard")</label>
      <input type="text" name="code" value="<?= h((string) $codeValue) ?>" required />

      <label>Name</label>
      <input type="text" name="name" value="<?= h((string) $nameValue) ?>" required />

      <label>Preis/Monat (€)</label>
      <input type="number" name="price_monthly" value="<?= h((string) $priceValue) ?>" min="0" step="0.01" required />

      <label>Max. Mitglieder</label>
      <input type="number" name="max_members" value="<?= (int) $maxMembersValue ?>" min="1" required />

      <label>Max. Kalender-Abos</label>
      <input type="number" name="max_calendar_streams" value="<?= (int) $maxStreamsValue ?>" min="0" required />

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Speichern</button>
        <a href="admin_plans.php" class="btn btn-secondary">Abbrechen</a>
        <div class="spacer"></div>
      </div>
    </form>
  </div>
</div>
</body>
</html>
