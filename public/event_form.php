<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$membersRes = $client->request('GET', '/api/members');
handleUnauthorized($membersRes);
$members = $membersRes['ok'] ? $membersRes['data'] : [];

$back = sanitizeCalendarBack($_GET['back'] ?? '');
$eventId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$error = $_GET['error'] ?? null;

$event = null;
if ($eventId) {
    $res = $client->request('GET', "/api/events/{$eventId}");
    handleUnauthorized($res);
    if (!$res['ok']) {
        header('Location: calendar.php?' . $back . '&error=' . urlencode($res['data']['error'] ?? 'Termin nicht gefunden.'));
        exit;
    }
    $event = $res['data'];
}

// -- Eigene Erinnerungen zu diesem Termin (nur bei bestehenden Terminen) ---
$reminders = [];
$reminderDefaultMinutes = 30;
if ($eventId) {
    $remindersRes = $client->request('GET', "/api/events/{$eventId}/reminders");
    handleUnauthorized($remindersRes);
    $reminders = $remindersRes['ok'] ? $remindersRes['data'] : [];

    $prefsRes = $client->request('GET', '/api/notification-preferences');
    handleUnauthorized($prefsRes);
    if ($prefsRes['ok']) {
        $reminderDefaultMinutes = $prefsRes['data']['reminder_default_minutes'];
    }
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

// Serien-Status nur relevant bei bestehenden Terminen: der Master trägt
// die recurrence_rule, eine Instanz verweist per recurrence_parent_id
// darauf. Wiederholungen lassen sich aktuell nur beim NEU-Anlegen
// festlegen, nicht nachträglich ändern (siehe README).
$isSeriesMaster = $event && !empty($event['recurrence_rule']) && empty($event['recurrence_parent_id']);
$isSeriesInstance = $event && !empty($event['recurrence_parent_id']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title><?= $event ? 'Termin bearbeiten' : 'Neuer Termin' ?></title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<div class="main-area">
  <div class="page-header"><?= brandLogo() ?></div>
  <div class="form-card">
    <h1><?= $event ? 'Termin bearbeiten' : 'Neuer Termin' ?></h1>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="event_save.php">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= $eventId ? (int) $eventId : '' ?>" />
      <input type="hidden" name="back" value="<?= h($back) ?>" />

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

      <?php if (!$event): ?>
        <label>Wiederholung</label>
        <select name="repeat_freq">
          <option value="none">Keine Wiederholung</option>
          <option value="daily">Täglich</option>
          <option value="weekly">Wöchentlich</option>
          <option value="monthly">Monatlich</option>
          <option value="yearly">Jährlich</option>
        </select>
        <div class="row" style="margin-top:6px;align-items:center;">
          <label class="checkbox-label"><input type="radio" name="repeat_end_type" value="count" checked /> Anzahl Wiederholungen</label>
          <input type="number" name="repeat_count" value="12" min="1" max="366" />
        </div>
        <div class="row" style="align-items:center;">
          <label class="checkbox-label"><input type="radio" name="repeat_end_type" value="until" /> Enddatum</label>
          <input type="date" name="repeat_until" />
        </div>
        <p class="hint-text">Wird nur beim Anlegen berücksichtigt - eine bestehende Serie lässt sich aktuell nicht nachträglich in der Häufigkeit ändern.</p>
      <?php elseif ($isSeriesMaster || $isSeriesInstance): ?>
        <p class="hint-text">
          <?= $isSeriesMaster ? 'Dies ist der erste Termin einer Wiederholungsserie.' : 'Dies ist ein Termin aus einer Wiederholungsserie.' ?>
          Änderungen hier betreffen nur diesen einzelnen Termin.
        </p>
      <?php endif; ?>

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
        <a href="calendar.php?<?= h($back) ?>" class="btn btn-secondary">Abbrechen</a>
        <div class="spacer"></div>
      </div>
    </form>

    <?php if ($event): ?>
      <div style="display:flex;gap:10px;margin-top:12px;">
        <form method="post" action="event_delete.php" style="margin:0;">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= (int) $eventId ?>" />
          <input type="hidden" name="back" value="<?= h($back) ?>" />
          <button type="submit" class="btn <?= $isSeriesMaster ? 'btn-secondary' : 'btn-danger' ?>">
            <?= $isSeriesMaster ? 'Nur diesen Termin löschen' : 'Termin löschen' ?>
          </button>
        </form>
        <?php if ($isSeriesMaster): ?>
          <form method="post" action="event_delete_series.php" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $eventId ?>" />
            <input type="hidden" name="back" value="<?= h($back) ?>" />
            <button type="submit" class="btn btn-danger">Ganze Serie löschen</button>
          </form>
        <?php endif; ?>
      </div>

      <h2 style="font-size:16px;margin-top:28px;">Meine Erinnerungen</h2>
      <p class="hint-text">
        Nur für dich persönlich, unabhängig davon, wer den Termin angelegt
        hat. Zugestellt per E-Mail und/oder Push, je nach deinen
        <a href="notification_settings.php">Benachrichtigungseinstellungen</a>.
      </p>

      <?php if (empty($reminders)): ?>
        <p class="hint-text">Noch keine Erinnerung für diesen Termin eingerichtet.</p>
      <?php else: ?>
        <div class="members-list">
          <?php foreach ($reminders as $r): ?>
            <div class="member-row">
              <span class="name"><?= h(formatReminderOffset((int) $r['remind_before_minutes'])) ?></span>
              <?php if ($r['sent']): ?><span class="badge profile">Bereits versendet</span><?php endif; ?>
              <form method="post" action="reminder_delete.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>" />
                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>" />
                <input type="hidden" name="back" value="<?= h($back) ?>" />
                <button type="submit" class="btn btn-secondary">Entfernen</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="reminder_add.php" style="margin-top:12px;">
        <?= csrfField() ?>
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>" />
        <input type="hidden" name="back" value="<?= h($back) ?>" />
        <label>Erinnerung hinzufügen</label>
        <div class="row">
          <select name="remind_before_minutes">
            <?php foreach ([15 => '15 Minuten vorher', 30 => '30 Minuten vorher', 60 => '1 Stunde vorher', 120 => '2 Stunden vorher', 1440 => '1 Tag vorher', 2880 => '2 Tage vorher'] as $value => $label): ?>
              <option value="<?= $value ?>" <?= $reminderDefaultMinutes === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-secondary">Hinzufügen</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
