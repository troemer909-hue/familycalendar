$error = $_GET['error'] ?? null;
?>
<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();
$isAdmin = isset($_SESSION['fk_role']) && $_SESSION['fk_role'] === 'admin';

$pointsRes = $client->request('GET', '/api/member-points');
handleUnauthorized($pointsRes);
$balances = $pointsRes['ok'] ? $pointsRes['data'] : [];

$adjustSuccess = null;
$adjustError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrf();
    if ($_POST['action'] === 'adjust') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $points = (int) ($_POST['points'] ?? 0);
        $reason = $_POST['reason'] ?? '';
        if ($memberId > 0 && $points !== 0 && in_array($reason, ['manual_adjustment', 'redemption'], true)) {
            $adjRes = $client->request('POST', "/api/member-points/{$memberId}/adjust", [
                'points' => $points, 'reason' => $reason,
            ]);
            if ($adjRes['ok']) {
                $pointsRes = $client->request('GET', '/api/member-points');
                $balances = $pointsRes['ok'] ? $pointsRes['data'] : [];
                $adjustSuccess = 'Punkte erfolgreich aktualisiert.';
            } else {
                $adjustError = $adjRes['data']['error'] ?? 'Fehler bei der Anpassung.';
            }
        } else {
            $adjustError = 'Ungültige Werte.';
        }
    }
}

$historyMemberId = isset($_GET['member_id']) ? (int) $_GET['member_id'] : null;
$history = [];
if ($historyMemberId > 0) {
    $allowed = $isAdmin;
    foreach ($balances as $b) { if ((int)$b['member_id'] === $historyMemberId) { $allowed = true; break; } }
    if ($allowed) {
        $histRes = $client->request('GET', "/api/member-points/{$historyMemberId}/history");
        $history = $histRes['ok'] ? $histRes['data'] : [];
    }
}

$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Punktestand - Familienkalender</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('points') ?>
  <div class="form-card" style="max-width:720px;">
    <h1>Punktestand</h1>
    <p><a href="calendar.php" class="hint-text">&larr; Zurück zum Kalender</a></p>

    <?php if ($adjustSuccess): ?><p class="success-box"><?= h($adjustSuccess) ?></p><?php endif; ?>
    <?php if ($adjustError): ?><p class="error-box"><?= h($adjustError) ?></p><?php endif; ?>

    <h2 style="font-size:16px;margin-top:20px;">Aktuelle Punktestände</h2>
    <?php if (empty($balances)): ?>
      <p class="hint-text">Noch keine Punkte vergeben.</p>
    <?php else: ?>
      <div class="members-list">
        <?php foreach ($balances as $b): ?>
          <div class="member-row" style="align-items:center;">
            <div style="flex:1"><strong><?= h($b['display_name']) ?></strong></div>
            <span class="badge" style="background:#f59e0b;color:#fff;padding:6px 12px;">
              <?= (int) $b['balance'] ?> Punkte
            </span>
            <a href="?member_id=<?= (int) $b['member_id'] ?>" class="btn btn-secondary" style="font-size:13px;padding:4px 8px;">Verlauf</a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <h2 style="font-size:16px;margin-top:28px;">Punkte manuell anpassen</h2>
    <form method="post" action="member_points.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="adjust" />
      <label>Mitglied</label>
      <select name="member_id" required>
        <option value="">-- wählen --</option>
        <?php foreach ($balances as $b): ?>
          <option value="<?= (int) $b['member_id'] ?>"><?= h($b['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Punkte (vorzeichenbehaftet)</label>
      <input type="number" name="points" min="-999" max="999" placeholder="z.B. 10 oder -5" required />
      <label>Grund</label>
      <select name="reason" required>
        <option value="manual_adjustment">Manuelle Korrektur</option>
        <option value="redemption">Einlösung (Abzug)</option>
      </select>
      <div class="form-actions" style="margin-top:12px;">
        <button type="submit" class="btn btn-primary">Anpassen</button>
      </div>
    </form>
    <?php endif; ?>

    <?php if (!empty($history)): ?>
    <h2 style="font-size:16px;margin-top:28px;">Verlauf</h2>
    <p><a href="member_points.php" class="hint-text">&larr; Übersicht</a></p>
    <div class="members-list">
      <?php foreach ($history as $h): ?>
        <div class="member-row" style="align-items:center;">
          <div style="flex:1">
            <?php if ($h['task_title']): ?><strong><?= h($h['task_title']) ?></strong><br /><?php endif; ?>
            <span class="hint-text"><?php if ($h['created_by_name']): ?>von <?= h($h['created_by_name']) ?><?php endif; ?> · <?= h($h['created_at']) ?></span>
          </div>
          <span class="badge" style="background:<?= (int)$h['points']>=0?'#10b981':'#ef4444' ?>;color:#fff;padding:6px 12px;">
            <?= (int)$h['points']>0?'+':'' ?><?=(int)$h['points']?>
          </span>
          <span class="hint-text" style="margin-left:8px;"><?= h($h['reason']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?= appChromeClose('points') ?>
</body>
</html>