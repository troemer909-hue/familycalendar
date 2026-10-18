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
  <title>Einladen</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('members') ?>
  <div class="form-card">
    <h1>Einladen</h1>
    <p class="hint-text">
      <strong><?= h($target['display_name']) ?></strong> bekommt eine
      E-Mail mit einem Link, um das eigene Passwort selbst festzulegen -
      bisherige Termine und Historie bleiben dabei erhalten.
    </p>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="member_invite.php">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= $id ?>" />
      <label>E-Mail</label>
      <input type="email" name="email" required />

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Einladung senden</button>
        <a href="members.php" class="btn btn-secondary">Abbrechen</a>
        <div class="spacer"></div>
      </div>
    </form>
  </div>
<?= appChromeClose('members') ?>
</body>
</html>
