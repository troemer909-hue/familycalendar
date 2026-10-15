<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$id = (int) ($_GET['id'] ?? 0);
$error = $_GET['error'] ?? null;

$membersRes = $client->request('GET', '/api/members');
handleUnauthorized($membersRes);
$members = $membersRes['ok'] ? $membersRes['data'] : [];

$target = null;
foreach ($members as $m) {
    if ((int) $m['id'] === $id) {
        $target = $m;
        break;
    }
}

if (!$target) {
    header('Location: members.php?error=' . urlencode('Person nicht gefunden.'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Admin-Rolle übergeben</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="main-area">
  <div class="form-card">
    <h1>Admin-Rolle übergeben</h1>
    <p class="hint-text">
      Die Familienadmin-Rolle wird an <strong><?= h($target['display_name']) ?></strong>
      übergeben. Du selbst wirst danach zu einem normalen Mitglied und
      kannst z.B. keine anderen Personen mehr hinzufügen oder Kalenderquellen
      verwalten – <?= h($target['display_name']) ?> dann schon.
    </p>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="member_transfer_admin.php">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int) $id ?>" />
      <div class="form-actions">
        <button type="submit" class="btn btn-danger">Ja, Admin-Rolle übergeben</button>
        <a href="members.php" class="btn btn-secondary">Abbrechen</a>
        <div class="spacer"></div>
      </div>
    </form>
  </div>
</div>
</body>
</html>
