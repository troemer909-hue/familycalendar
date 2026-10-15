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

// -- Eigene Erinnerungen zu diesem Termin (nur bei bestehenden Terminen) ---
$reminders = [];
if ($eventId) {
    $remindersRes = $client->request('GET', "/api/events/{$eventId}/reminders");
    handleUnauthorized($remindersRes);
    $reminders = $remindersRes['ok'] ? $remindersRes['data'] : [];
}

function formatReminderOffset(int $minutes): string
{
    if ($minutes % 1440 === 0) {
        $days = intdiv($minutes, 1440);
        return $days === 1 ? '1 Tag vorher' : "{$days} Tage vorher";
    }
    if ($minutes % 60 === 0) {
        $hours = intdiv($minutes, 60);
        return $hours === 1 ? '1 Stunde vorher' : "{$hours} Stunden vorher";
    }
    return "{$minutes} Minuten vorher";
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

      <h2 style="font-size:16px;margin-top:28px;">Meine Erinnerungen</h2>
      <p class="hint-text">Nur für dich persönlich - per E-Mail, unabhängig davon, wer den Termin angelegt hat.</p>

      <?php if (empty($reminders)): ?>
        <p class="hint-text">Noch keine Erinnerung für diesen Termin eingerichtet.</p>
      <?php else: ?>
        <div class="members-list">
          <?php foreach ($reminders as $r): ?>
            <div class="member-row">
              <span class="name"><?= h(formatReminderOffset((int) $r['remind_before_minutes'])) ?></span>
              <?php if ($r['sent']): ?><span class="badge profile">Bereits versendet</span><?php endif; ?>
              <form method="post" action="reminder_delete.php" style="margin:0;">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>" />
                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>" />
                <input type="hidden" name="back_month" value="<?= h($backMonth) ?>" />
                <button type="submit" class="btn btn-secondary">Entfernen</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="reminder_add.php" style="margin-top:12px;">
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>" />
        <input type="hidden" name="back_month" value="<?= h($backMonth) ?>" />
        <label>Erinnerung hinzufügen</label>
        <div class="row">
          <select name="remind_before_minutes">
            <option value="15">15 Minuten vorher</option>
            <option value="30" selected>30 Minuten vorher</option>
            <option value="60">1 Stunde vorher</option>
            <option value="120">2 Stunden vorher</option>
            <option value="1440">1 Tag vorher</option>
            <option value="2880">2 Tage vorher</option>
          </select>
          <button type="submit" class="btn btn-secondary">Hinzufügen</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
