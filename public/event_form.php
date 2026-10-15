<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$membersRes = $client->request('GET', '/api/members');
handleUnauthorized($membersRes);
$members = $membersRes['ok'] ? $membersRes['data'] : [];

$backMonth = $_GET['back_month'] ?? date('Y-m');
$eventId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$error = $_GET['error'] ?? null;

$event = null;
if ($eventId) {
    $res = $client->request('GET', "/api/events/{$eventId}");
    handleUnauthorized($res);
    if (!$res['ok']) {
        header('Location: calendar.php?month=' . urlencode($backMonth) . '&error=' . urlencode($res['data']['error'] ?? 'Termin nicht gefunden.'));
        exit;
    }
    $event = $res['data'];
}

// -- Vorbelegung für "neuer Termin an diesem Tag" --------------------------
$prefillDate = $_GET['date'] ?? date('Y-m-d');

function toDatetimeLocal(string $mysqlDatetime): string
{
    return substr(str_replace(' ', 'T', $mysqlDatetime), 0, 16);
}

$startValue = $event ? toDatetimeLocal($event['starts_at']) : $prefillDate . 'T09:00';
$endValue = $event ? toDatetimeLocal($event['ends_at']) : $prefillDate . 'T10:00';
$titleValue = $event['title'] ?? '';
$locationValue = $event['location'] ?? '';
$categoryValue = $event['category'] ?? '';
$descriptionValue = $event['description'] ?? '';
$allDayChecked = !empty($event['all_day']);
$scopeValue = $event['scope'] ?? 'personal';
$selectedParticipants = $event['participant_ids'] ?? [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title><?= $event ? 'Termin bearbeiten' : 'Neuer Termin' ?></title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="main-area">
  <div class="form-card">
    <h1><?= $event ? 'Termin bearbeiten' : 'Neuer Termin' ?></h1>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="event_save.php">
      <input type="hidden" name="id" value="<?= $eventId ? (int) $eventId : '' ?>" />
      <input type="hidden" name="back_month" value="<?= h($backMonth) ?>" />

      <label>Titel *</label>
      <input type="text" name="title" value="<?= h($titleValue) ?>" required />

      <div class="row">
        <div>
          <label>Beginn *</label>
          <input type="datetime-local" name="starts_at" value="<?= h($startValue) ?>" required />
        </div>
        <div>
          <label>Ende *</label>
          <input type="datetime-local" name="ends_at" value="<?= h($endValue) ?>" required />
        </div>
      </div>

      <label class="checkbox-label">
        <input type="checkbox" name="all_day" value="1" <?= $allDayChecked ? 'checked' : '' ?> /> Ganztägig
      </label>

      <label>Wer ist betroffen? *</label>
      <div class="row">
        <label class="checkbox-label"><input type="radio" name="scope" value="family" <?= $scopeValue === 'family' ? 'checked' : '' ?> /> Ganze Familie</label>
        <label class="checkbox-label"><input type="radio" name="scope" value="personal" <?= $scopeValue === 'personal' ? 'checked' : '' ?> /> Bestimmte Person(en)</label>
      </div>
      <p class="hint-text">Die Auswahl unten wird nur bei „Bestimmte Person(en)" berücksichtigt.</p>
      <div class="participant-checkboxes">
        <?php foreach ($members as $m): ?>
          <label>
            <input type="checkbox" name="participant_ids[]" value="<?= (int) $m['id'] ?>"
              <?= in_array($m['id'], $selectedParticipants, true) ? 'checked' : '' ?> />
            <span class="dot" style="background:<?= h($m['display_color']) ?>"></span>
            <?= h($m['display_name']) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <label>Ort</label>
      <input type="text" name="location" value="<?= h($locationValue) ?>" />

      <label>Kategorie</label>
      <input type="text" name="category" value="<?= h($categoryValue) ?>" placeholder="z.B. Schule, Sport, Arzt" />

      <label>Beschreibung</label>
      <textarea name="description" rows="3"><?= h($descriptionValue) ?></textarea>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Speichern</button>
        <a href="calendar.php?month=<?= h($backMonth) ?>" class="btn btn-secondary">Abbrechen</a>
        <div class="spacer"></div>
      </div>
    </form>

    <?php if ($event): ?>
      <form method="post" action="event_delete.php" style="margin-top:12px;">
        <input type="hidden" name="id" value="<?= (int) $eventId ?>" />
        <input type="hidden" name="back_month" value="<?= h($backMonth) ?>" />
        <button type="submit" class="btn btn-danger">Termin löschen</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
