<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$statusFilter = $_GET['status'] ?? '';
$statuses = ['trial', 'active', 'suspended', 'cancelled'];
$statusLabels = ['trial' => 'Trial', 'active' => 'Aktiv', 'suspended' => 'Pausiert', 'cancelled' => 'Gekündigt'];

$query = in_array($statusFilter, $statuses, true) ? "?status={$statusFilter}" : '';
$familiesRes = $client->request('GET', "/api/admin/families{$query}");
handleUnauthorized($familiesRes);
handleForbidden($familiesRes);
$families = $familiesRes['ok'] ? $familiesRes['data'] : [];
$familiesError = $familiesRes['ok'] ? null : ($familiesRes['data']['error'] ?? 'Familien konnten nicht geladen werden.');

$plansRes = $client->request('GET', '/api/admin/plans');
handleUnauthorized($plansRes);
handleForbidden($plansRes);
$plans = $plansRes['ok'] ? $plansRes['data'] : [];

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Plattform-Verwaltung – Familien</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="main-area">
  <div class="form-card" style="max-width:980px;">
    <h1>Familien</h1>
    <p class="hint-text">
      Plan-Zuordnung und Abrechnungsstatus jeder Familie auf der
      Plattform. Reine Status-Pflege (kein Zahlungsabwicklungs-Flow) -
      siehe <a href="admin_plans.php">Pläne verwalten</a>.
    </p>

    <?php if ($notice): ?><p class="success-box"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($familiesError): ?><p class="error-box"><?= h($familiesError) ?></p><?php endif; ?>

    <form method="get" action="admin_families.php" class="admin-filter-row">
      <label style="margin:0;">Status filtern:</label>
      <select name="status">
        <option value="">Alle</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-secondary">Filtern</button>
    </form>

    <table class="admin-table">
      <thead>
        <tr>
          <th>Familie</th>
          <th>Status</th>
          <th>Plan</th>
          <th>Mitglieder</th>
          <th>Erstellt</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($families as $f): ?>
          <tr>
            <td><?= h($f['name']) ?></td>
            <td><span class="badge <?= h($f['status']) ?>"><?= h($statusLabels[$f['status']] ?? $f['status']) ?></span></td>
            <td><?= $f['plan_name'] ? h($f['plan_name']) : '<span class="hint-text">kein Plan</span>' ?></td>
            <td><?= (int) $f['member_count'] ?></td>
            <td><?= h(formatGermanDateTime($f['created_at'])) ?></td>
            <td style="white-space:nowrap;">
              <form method="post" action="admin_family_update.php" style="display:flex;gap:6px;margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>" />
                <select name="plan_id">
                  <option value="">Kein Plan</option>
                  <?php foreach ($plans as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= ((int) $f['plan_id'] === (int) $p['id']) ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="status">
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary">Speichern</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($families)): ?>
          <tr><td colspan="6" class="hint-text">Keine Familien gefunden.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <p style="margin-top:20px;">
      <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
    </p>
  </div>
</div>
</body>
</html>
