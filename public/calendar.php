<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$redirectError = $_GET['error'] ?? null;

$client = currentApiClient();

$membersRes = $client->request('GET', '/api/members');
handleUnauthorized($membersRes);
if (!$membersRes['ok']) {
    $membersError = $membersRes['data']['error'] ?? 'Mitglieder konnten nicht geladen werden.';
    $members = [];
} else {
    $members = $membersRes['data'];
}
$membersById = [];
foreach ($members as $m) {
    $membersById[$m['id']] = $m;
}

// -- Monat aus ?month=YYYY-MM lesen, sonst aktueller Monat -----------------
$monthParam = $_GET['month'] ?? date('Y-m');
try {
    $monthStart = new DateTimeImmutable($monthParam . '-01');
} catch (Exception $e) {
    $monthStart = new DateTimeImmutable('first day of this month');
}
$monthEnd = $monthStart->modify('last day of this month');

$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$thisMonth = (new DateTimeImmutable('now'))->format('Y-m');

// -- ausgeblendete Mitglieder aus ?hide=1,3,7 -------------------------------
$hiddenIds = [];
if (!empty($_GET['hide'])) {
    $hiddenIds = array_map('intval', explode(',', (string) $_GET['hide']));
}

// -- Termine laden (mit etwas Puffer vor/nach dem Monat für die Randtage) --
$rangeFrom = $monthStart->modify('-7 days')->format('Y-m-d');
$rangeTo = $monthEnd->modify('+7 days')->format('Y-m-d');

$eventsRes = $client->request('GET', "/api/events?from={$rangeFrom}&to={$rangeTo}");
handleUnauthorized($eventsRes);
$events = $eventsRes['ok'] ? $eventsRes['data'] : [];
$eventsError = $eventsRes['ok'] ? null : ($eventsRes['data']['error'] ?? 'Termine konnten nicht geladen werden.');

// Termine nach Tag gruppieren (String YYYY-MM-DD -> Liste), unter
// Berücksichtigung des Sichtbarkeitsfilters (nur Anzeige, kein Sicherheitsfilter).
$eventsByDay = [];
foreach ($events as $ev) {
    $allHidden = !empty($ev['participant_ids'])
        && count(array_diff($ev['participant_ids'], $hiddenIds)) === 0;
    if ($allHidden) {
        continue;
    }

    $start = new DateTimeImmutable(substr($ev['starts_at'], 0, 10));
    $end = new DateTimeImmutable(substr($ev['ends_at'], 0, 10));
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $eventsByDay[$d->format('Y-m-d')][] = $ev;
    }
}

// -- Grid-Grenzen (Montag als Wochenstart, volle Wochen) -------------------
$startOffset = ((int) $monthStart->format('N')) - 1; // Mo=1..So=7 -> 0..6
$gridStart = $monthStart->modify("-{$startOffset} days");

$endOffset = 7 - (int) $monthEnd->format('N');
$gridEnd = $monthEnd->modify("+{$endOffset} days");

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<div class="app-layout">
  <aside class="sidebar">
    <h2>Familie</h2>
    <?php foreach ($members as $m): $isHidden = in_array($m['id'], $hiddenIds, true); ?>
      <?php
        $newHidden = $isHidden
          ? array_diff($hiddenIds, [$m['id']])
          : array_merge($hiddenIds, [$m['id']]);
      ?>
      <a href="<?= h(buildCalendarUrl($monthParam, $newHidden)) ?>" style="text-decoration:none;color:inherit;">
        <div class="member-chip<?= $isHidden ? ' inactive' : '' ?>">
          <span class="dot" style="background:<?= h($m['display_color']) ?>"></span>
          <span><?= h($m['display_name']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>

    <p style="margin-top:20px;">
      <a href="members.php" class="btn btn-secondary btn-block">Mitglieder verwalten</a>
    </p>
    <p>
      <a href="calendar_sources.php" class="btn btn-secondary btn-block">Kalenderquellen verwalten</a>
    </p>
    <p>
      <a href="push_subscribe.php" class="btn btn-secondary btn-block">Push-Benachrichtigungen</a>
    </p>
    <p>
      <a href="notification_settings.php" class="btn btn-secondary btn-block">Benachrichtigungseinstellungen</a>
    </p>
    <p>
      <a href="ics_export.php" class="btn btn-secondary btn-block">Kalender-Abo (ICS-Export)</a>
    </p>
    <p>
      <a href="admin_families.php" class="btn btn-secondary btn-block">Plattform-Verwaltung</a>
    </p>
    <p>
      <a href="self_update.php" class="btn btn-secondary btn-block">Selbst-Update</a>
    </p>
    <p>
      <a href="logout.php" class="hint-text">Abmelden</a>
    </p>
  </aside>

  <main class="main-area">
    <div class="toolbar">
      <div class="month-nav">
        <a class="btn" href="<?= h(buildCalendarUrl($prevMonth, $hiddenIds)) ?>">‹</a>
        <h2 id="current-month-label"><?= h(formatGermanMonth($monthStart)) ?></h2>
        <a class="btn" href="<?= h(buildCalendarUrl($nextMonth, $hiddenIds)) ?>">›</a>
        <a class="btn btn-secondary" href="<?= h(buildCalendarUrl($thisMonth, $hiddenIds)) ?>">Heute</a>
      </div>
      <a class="btn btn-primary" href="event_form.php?date=<?= h($today) ?>&back_month=<?= h($monthParam) ?>">+ Neuer Termin</a>
    </div>

    <?php if ($redirectError): ?><p class="error-box"><?= h($redirectError) ?></p><?php endif; ?>
    <?php if (!empty($membersError)): ?><p class="error-box"><?= h($membersError) ?></p><?php endif; ?>
    <?php if ($eventsError): ?><p class="error-box"><?= h($eventsError) ?></p><?php endif; ?>

    <div class="calendar-grid">
      <?php foreach ($weekdays as $wd): ?>
        <div class="calendar-weekday"><?= $wd ?></div>
      <?php endforeach; ?>

      <?php for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')):
        $dayStr = $day->format('Y-m-d');
        $isOtherMonth = $day->format('Y-m') !== $monthStart->format('Y-m');
        $isToday = $dayStr === $today;
        $dayEvents = $eventsByDay[$dayStr] ?? [];
      ?>
        <div class="calendar-day<?= $isOtherMonth ? ' other-month' : '' ?><?= $isToday ? ' today' : '' ?>">
          <div class="day-header">
            <span class="day-number"><?= (int) $day->format('j') ?></span>
            <a class="add-link" href="event_form.php?date=<?= h($dayStr) ?>&back_month=<?= h($monthParam) ?>" title="Termin anlegen">+</a>
          </div>
          <?php foreach ($dayEvents as $ev):
            $isFamily = $ev['scope'] === 'family';
            $color = '#64748b';
            if (!$isFamily && !empty($ev['participant_ids']) && isset($membersById[$ev['participant_ids'][0]])) {
                $color = $membersById[$ev['participant_ids'][0]]['display_color'];
            }
          ?>
            <a href="event_form.php?id=<?= (int) $ev['id'] ?>&back_month=<?= h($monthParam) ?>"
               class="event-chip<?= $isFamily ? ' family-event' : '' ?>"
               style="<?= $isFamily ? '' : 'background:' . h($color) ?>">
              <?= $isFamily ? '👪 ' : '' ?><?= h($ev['title']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
    </div>
  </main>
</div>
</body>
</html>
