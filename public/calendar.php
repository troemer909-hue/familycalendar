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

// -- Ansicht (Monat/Woche/Tag) + Anker-Datum --------------------------------
// Alle drei Ansichten tragen denselben "view"-Parameter, aber je nach
// Ansicht ist "month" (Monat) ODER "date" (Woche/Tag) der maßgebliche
// Anker - siehe calendarQueryParams()/buildCalendarUrl() in Helpers.php.
// Hier wird daraus für BEIDE Fälle ein einheitlicher $anchor berechnet,
// damit sich zwischen den Ansichten wechseln lässt, ohne die aktuelle
// Position zu verlieren.
$requestedView = $_GET['view'] ?? 'month';
$view = in_array($requestedView, ['month', 'week', 'day'], true) ? $requestedView : 'month';
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

if ($view === 'month') {
    $monthParam = $_GET['month'] ?? date('Y-m');
    try {
        $monthStart = new DateTimeImmutable($monthParam . '-01');
    } catch (Exception $e) {
        $monthStart = new DateTimeImmutable('first day of this month');
    }
    $monthParam = $monthStart->format('Y-m');
    // Anker für die Woche/Tag-Tabs: "heute", falls der angezeigte Monat
    // den heutigen Tag enthält, sonst der Monatserste.
    $anchor = $monthStart->format('Y-m') === (new DateTimeImmutable('today'))->format('Y-m')
        ? new DateTimeImmutable('today')
        : $monthStart;
} else {
    $dateParam = $_GET['date'] ?? date('Y-m-d');
    try {
        $anchor = new DateTimeImmutable($dateParam);
    } catch (Exception $e) {
        $anchor = new DateTimeImmutable('today');
    }
    $monthStart = $anchor->modify('first day of this month');
    $monthParam = $monthStart->format('Y-m');
}
$dateParam = $anchor->format('Y-m-d');

// -- ausgeblendete Mitglieder aus ?hide=1,3,7 -------------------------------
$hiddenIds = [];
if (!empty($_GET['hide'])) {
    $hiddenIds = array_map('intval', explode(',', (string) $_GET['hide']));
}

// "back": trägt die aktuelle Ansicht+Position in Termin-/Erinnerungs-
// formulare, damit man nach Speichern/Löschen in dieselbe Ansicht (nicht
// immer zurück auf "Monat") zurückkommt - siehe sanitizeCalendarBack().
$back = http_build_query(calendarQueryParams($view, $monthParam, $dateParam, $hiddenIds));

// -- Zeitraum je Ansicht bestimmen ------------------------------------------
$weekdayLabels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

if ($view === 'month') {
    $monthEnd = $monthStart->modify('last day of this month');
    $startOffset = ((int) $monthStart->format('N')) - 1; // Mo=1..So=7 -> 0..6
    $gridStart = $monthStart->modify("-{$startOffset} days");
    $endOffset = 7 - (int) $monthEnd->format('N');
    $gridEnd = $monthEnd->modify("+{$endOffset} days");

    $rangeFrom = $gridStart->format('Y-m-d');
    $rangeTo = $gridEnd->format('Y-m-d');

    $prevUrl = buildCalendarUrl('month', $monthStart->modify('-1 month')->format('Y-m'), '', $hiddenIds);
    $nextUrl = buildCalendarUrl('month', $monthStart->modify('+1 month')->format('Y-m'), '', $hiddenIds);
    $todayUrl = buildCalendarUrl('month', (new DateTimeImmutable('now'))->format('Y-m'), '', $hiddenIds);
    $heading = formatGermanMonth($monthStart);
} elseif ($view === 'week') {
    $weekStartOffset = ((int) $anchor->format('N')) - 1;
    $gridStart = $anchor->modify("-{$weekStartOffset} days");
    $gridEnd = $gridStart->modify('+6 days');

    $rangeFrom = $gridStart->format('Y-m-d');
    $rangeTo = $gridEnd->format('Y-m-d');

    $prevUrl = buildCalendarUrl('week', '', $anchor->modify('-7 days')->format('Y-m-d'), $hiddenIds);
    $nextUrl = buildCalendarUrl('week', '', $anchor->modify('+7 days')->format('Y-m-d'), $hiddenIds);
    $todayUrl = buildCalendarUrl('week', '', $today, $hiddenIds);
    $heading = 'KW ' . $anchor->format('W') . ' – ' . formatGermanMonth($anchor);
} else { // day
    $gridStart = $anchor;
    $gridEnd = $anchor;

    $rangeFrom = $anchor->format('Y-m-d');
    $rangeTo = $anchor->format('Y-m-d');

    $prevUrl = buildCalendarUrl('day', '', $anchor->modify('-1 day')->format('Y-m-d'), $hiddenIds);
    $nextUrl = buildCalendarUrl('day', '', $anchor->modify('+1 day')->format('Y-m-d'), $hiddenIds);
    $todayUrl = buildCalendarUrl('day', '', $today, $hiddenIds);
    $heading = formatGermanFullDate($anchor);
}

// -- Termine laden -----------------------------------------------------------
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

if ($view === 'day') {
    // Agenda-Ansicht: chronologisch statt in API-Reihenfolge.
    $dayEventsForAgenda = $eventsByDay[$dateParam] ?? [];
    usort($dayEventsForAgenda, static fn (array $a, array $b) => strcmp($a['starts_at'], $b['starts_at']));
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Familienkalender</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
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
      <a href="<?= h(buildCalendarUrl($view, $monthParam, $dateParam, $newHidden)) ?>" style="text-decoration:none;color:inherit;">
        <div class="member-chip<?= $isHidden ? ' inactive' : '' ?>">
          <span class="dot" style="background:<?= h($m['display_color']) ?>"></span>
          <span><?= h($m['display_name']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>

    <nav class="sidebar-nav">
      <div class="nav-group">
        <span class="nav-group-label">Kalender</span>
        <a href="members.php" class="nav-link">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 5 18.5V20"/><circle cx="9.5" cy="8" r="3.2"/><path d="M15.5 8.2a2.8 2.8 0 1 1 1.9 5"/><path d="M19.5 20v-1.3a3 3 0 0 0-2-2.8"/></svg>
          <span>Mitglieder verwalten</span>
        </a>
        <a href="calendar_sources.php" class="nav-link">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 12a8 8 0 0 1 14.6-4.6M20 12a8 8 0 0 1-14.6 4.6"/><path d="M18.6 3.4V8h-4.6M5.4 20.6V16H10"/></svg>
          <span>Kalenderquellen verwalten</span>
        </a>
        <a href="ics_export.php" class="nav-link">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0 0-4-4m4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
          <span>Kalender-Abo (ICS-Export)</span>
        </a>
      </div>

      <div class="nav-group">
        <span class="nav-group-label">Benachrichtigungen</span>
        <a href="push_subscribe.php" class="nav-link">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 1 0-12 0c0 4-1.5 6-2 7h16c-.5-1-2-3-2-7"/><path d="M10 19a2 2 0 0 0 4 0"/></svg>
          <span>Push-Benachrichtigungen</span>
        </a>
        <a href="notification_settings.php" class="nav-link">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 13.5a7.6 7.6 0 0 0 0-3l1.8-1.4-2-3.4-2.1.7a7.7 7.7 0 0 0-2.6-1.5L14 2.5h-4l-.5 2.4a7.7 7.7 0 0 0-2.6 1.5l-2.1-.7-2 3.4L4.6 10.5a7.6 7.6 0 0 0 0 3l-1.8 1.4 2 3.4 2.1-.7a7.7 7.7 0 0 0 2.6 1.5l.5 2.4h4l.5-2.4a7.7 7.7 0 0 0 2.6-1.5l2.1.7 2-3.4z"/></svg>
          <span>Benachrichtigungseinstellungen</span>
        </a>
      </div>

      <?php if (isPlatformAdmin()): ?>
      <div class="nav-group">
        <span class="nav-group-label">Verwaltung</span>
        <a href="admin_families.php" class="nav-link">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4 6v6c0 5 3.5 7.5 8 9 4.5-1.5 8-4 8-9V6z"/></svg>
          <span>Plattform-Verwaltung</span>
        </a>
      </div>
      <?php endif; ?>

      <div class="nav-group nav-group-footer">
        <a href="logout.php" class="nav-link nav-link-muted">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
          <span>Abmelden</span>
        </a>
      </div>
    </nav>
  </aside>

  <main class="main-area">
    <div class="toolbar">
      <div class="month-nav">
        <a class="btn" href="<?= h($prevUrl) ?>">‹</a>
        <h2 id="current-month-label"><?= h($heading) ?></h2>
        <a class="btn" href="<?= h($nextUrl) ?>">›</a>
        <a class="btn btn-secondary" href="<?= h($todayUrl) ?>">Heute</a>
      </div>
      <div class="view-switch">
        <a href="<?= h(buildCalendarUrl('month', $monthParam, $dateParam, $hiddenIds)) ?>" class="<?= $view === 'month' ? 'active' : '' ?>">Monat</a>
        <a href="<?= h(buildCalendarUrl('week', $monthParam, $dateParam, $hiddenIds)) ?>" class="<?= $view === 'week' ? 'active' : '' ?>">Woche</a>
        <a href="<?= h(buildCalendarUrl('day', $monthParam, $dateParam, $hiddenIds)) ?>" class="<?= $view === 'day' ? 'active' : '' ?>">Tag</a>
      </div>
      <a class="btn btn-primary" href="event_form.php?date=<?= h($dateParam) ?>&back=<?= urlencode($back) ?>">+ Neuer Termin</a>
    </div>

    <?php if ($redirectError): ?><p class="error-box"><?= h($redirectError) ?></p><?php endif; ?>
    <?php if (!empty($membersError)): ?><p class="error-box"><?= h($membersError) ?></p><?php endif; ?>
    <?php if ($eventsError): ?><p class="error-box"><?= h($eventsError) ?></p><?php endif; ?>

    <?php if ($view === 'day'): ?>

      <div class="day-agenda">
        <?php if (empty($dayEventsForAgenda)): ?>
          <p class="day-agenda-empty">Keine Termine an diesem Tag.</p>
        <?php endif; ?>
        <?php foreach ($dayEventsForAgenda as $ev):
          $isFamily = $ev['scope'] === 'family';
          $color = eventColor($ev, $membersById);
        ?>
          <a href="event_form.php?id=<?= (int) $ev['id'] ?>&back=<?= urlencode($back) ?>" class="day-event">
            <span class="dot" style="background:<?= h($color) ?>"></span>
            <span class="day-event-time">
              <?= $ev['all_day'] ? 'Ganztägig' : h(substr($ev['starts_at'], 11, 5)) . '<span class="sep">–</span>' . h(substr($ev['ends_at'], 11, 5)) ?>
            </span>
            <span class="day-event-body">
              <span class="day-event-title"><?= $isFamily ? '👪 ' : '' ?><?= h($ev['title']) ?></span>
              <?php if (!empty($ev['location'])): ?><span class="day-event-location"><?= h($ev['location']) ?></span><?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>

    <?php else: ?>

      <div class="calendar-grid<?= $view === 'week' ? ' view-week' : '' ?>">
        <?php foreach ($weekdayLabels as $wd): ?>
          <div class="calendar-weekday"><?= $wd ?></div>
        <?php endforeach; ?>

        <?php for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')):
          $dayStr = $day->format('Y-m-d');
          $isOtherMonth = $view === 'month' && $day->format('Y-m') !== $monthStart->format('Y-m');
          $isToday = $dayStr === $today;
          $dayEvents = $eventsByDay[$dayStr] ?? [];
        ?>
          <div class="calendar-day<?= $isOtherMonth ? ' other-month' : '' ?><?= $isToday ? ' today' : '' ?>">
            <div class="day-header">
              <?php if ($view === 'week'): ?>
                <span class="weekday-label"><?= $weekdayLabels[((int) $day->format('N')) - 1] ?></span>
              <?php endif; ?>
              <span class="day-number"><?= (int) $day->format('j') ?></span>
              <a class="add-link" href="event_form.php?date=<?= h($dayStr) ?>&back=<?= urlencode($back) ?>" title="Termin anlegen">+</a>
            </div>
            <?php foreach ($dayEvents as $ev):
              $isFamily = $ev['scope'] === 'family';
              $color = eventColor($ev, $membersById);
            ?>
              <a href="event_form.php?id=<?= (int) $ev['id'] ?>&back=<?= urlencode($back) ?>"
                 class="event-chip<?= $isFamily ? ' family-event' : '' ?>"
                 style="<?= $isFamily ? '' : 'background:' . h($color) ?>">
                <?= $isFamily ? '👪 ' : '' ?><?= h($ev['title']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endfor; ?>
      </div>

    <?php endif; ?>
  </main>
</div>
</body>
</html>
