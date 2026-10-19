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

// -- Fällige Aufgaben laden (Phase 4 im Konzept: Kalender-Verzahnung) -------
// Bewusst NUR offene Aufgaben (siehe TaskListController::dueTasks) und als
// reine Tagesmarkierung dargestellt - KEINE events-Zeile, keine Vermischung
// mit Terminen (siehe docs/aufgabenlisten-konzept.md).
$tasksRes = $client->request('GET', "/api/tasks/due?from={$rangeFrom}&to={$rangeTo}");
handleUnauthorized($tasksRes);
$dueTasks = $tasksRes['ok'] ? $tasksRes['data'] : [];

$tasksByDay = [];
foreach ($dueTasks as $t) {
    $tasksByDay[$t['due_date']][] = $t;
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
<?php
// Mitglieder-Sichtbarkeitsfilter (Farbpunkte zum Ein-/Ausblenden) - nur
// auf calendar.php relevant, daher hier statt in appChromeOpen() selbst
// gebaut und als zusätzlicher Sidebar-Inhalt übergeben (siehe Helpers.php).
ob_start();
foreach ($members as $m):
    $isHidden = in_array($m['id'], $hiddenIds, true);
    $newHidden = $isHidden ? array_diff($hiddenIds, [$m['id']]) : array_merge($hiddenIds, [$m['id']]);
    ?>
    <a href="<?= h(buildCalendarUrl($view, $monthParam, $dateParam, $newHidden)) ?>" style="text-decoration:none;color:inherit;">
      <div class="member-chip<?= $isHidden ? ' inactive' : '' ?>">
        <span class="dot" style="background:<?= h($m['display_color']) ?>"></span>
        <span><?= h($m['display_name']) ?></span>
      </div>
    </a>
    <?php
endforeach;
$memberChipsHtml = '<h2>Familie</h2>' . ob_get_clean();

$mainAttrs = 'data-swipe-nav data-prev-url="' . h($prevUrl) . '" data-next-url="' . h($nextUrl) . '"';
echo appChromeOpen('calendar', $memberChipsHtml, $mainAttrs);
?>
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
      <a class="btn btn-primary toolbar-add" href="event_form.php?date=<?= h($dateParam) ?>&back=<?= urlencode($back) ?>">+ Neuer Termin</a>
    </div>

    <?php if ($redirectError): ?><p class="error-box"><?= h($redirectError) ?></p><?php endif; ?>
    <?php if (!empty($membersError)): ?><p class="error-box"><?= h($membersError) ?></p><?php endif; ?>
    <?php if ($eventsError): ?><p class="error-box"><?= h($eventsError) ?></p><?php endif; ?>

    <?php if ($view === 'day'): ?>

      <?php $dayTasks = $tasksByDay[$dateParam] ?? []; ?>
      <?php if (!empty($dayTasks)): ?>
        <div class="day-agenda" style="margin-bottom:12px;">
          <?php foreach ($dayTasks as $t): ?>
            <a href="task_list_view.php?id=<?= (int) $t['task_list_id'] ?>" class="day-event">
              <span class="dot task-chip-dot"></span>
              <span class="day-event-time">☐ Aufgabe</span>
              <span class="day-event-body">
                <span class="day-event-title"><?= h($t['title']) ?></span>
                <span class="day-event-location"><?= h($t['task_list_name']) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

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

    <?php elseif ($view === 'week'): ?>

      <div class="week-grid">
        <div class="week-grid-header">
          <div class="week-grid-time-gutter"></div>
          <?php for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')):
            $dayStr = $day->format('Y-m-d');
            $isToday = $dayStr === $today;
          ?>
            <div class="week-grid-daylabel<?= $isToday ? ' today' : '' ?>">
              <span class="weekday-label"><?= $weekdayLabels[((int) $day->format('N')) - 1] ?></span>
              <a class="day-number" href="<?= h(buildCalendarUrl('day', $monthParam, $dayStr, $hiddenIds)) ?>"><?= (int) $day->format('j') ?></a>
            </div>
          <?php endfor; ?>
        </div>

        <div class="week-grid-allday">
          <div class="week-grid-time-gutter"></div>
          <?php for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')):
            $dayStr = $day->format('Y-m-d');
            $allDayEvents = array_filter($eventsByDay[$dayStr] ?? [], static fn (array $ev): bool => !empty($ev['all_day']));
            $dayTasks = $tasksByDay[$dayStr] ?? [];
          ?>
            <div class="week-grid-allday-cell">
              <?php foreach ($allDayEvents as $ev):
                $isFamily = $ev['scope'] === 'family';
                $color = eventColor($ev, $membersById);
              ?>
                <a href="event_form.php?id=<?= (int) $ev['id'] ?>&back=<?= urlencode($back) ?>"
                   class="event-chip<?= $isFamily ? ' family-event' : '' ?>"
                   style="<?= $isFamily ? '' : 'background:' . h($color) ?>">
                  <?= $isFamily ? '👪 ' : '' ?><?= h($ev['title']) ?>
                </a>
              <?php endforeach; ?>
              <?php foreach ($dayTasks as $t): ?>
                <a href="task_list_view.php?id=<?= (int) $t['task_list_id'] ?>" class="task-chip" title="Aufgabe aus „<?= h($t['task_list_name']) ?>“">
                  ☐ <?= h($t['title']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endfor; ?>
        </div>

        <div class="week-grid-body" data-week-scroll>
          <div class="week-grid-hours">
            <?php for ($h = 0; $h < 24; $h++): ?>
              <div class="week-grid-hour-label" style="top: calc(var(--week-hour-height) * <?= $h ?>);"><?= sprintf('%02d:00', $h) ?></div>
            <?php endfor; ?>
          </div>
          <div class="week-grid-days">
            <?php
            $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
            for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')):
                $dayStr = $day->format('Y-m-d');
                $isToday = $dayStr === $today;
                $timedEvents = array_values(array_filter($eventsByDay[$dayStr] ?? [], static fn (array $ev): bool => empty($ev['all_day'])));
                $laidOut = layoutWeekDayEvents($timedEvents, $dayStr);
            ?>
              <div class="week-grid-day-col">
                <?php for ($h = 0; $h < 24; $h++): ?>
                  <div class="week-grid-hour-line" style="top: calc(var(--week-hour-height) * <?= $h ?>);"></div>
                <?php endfor; ?>
                <?php if ($isToday): ?>
                  <div class="week-grid-now-line" style="top: calc(var(--week-hour-height) * <?= $nowMinutes ?> / 60);"></div>
                <?php endif; ?>
                <?php foreach ($laidOut as $item):
                  $ev = $item['event'];
                  $isFamily = $ev['scope'] === 'family';
                  $color = eventColor($ev, $membersById);
                  $topFactor = $item['startMinutes'] / 60;
                  $heightFactor = ($item['endMinutes'] - $item['startMinutes']) / 60;
                  $widthPct = 100 / $item['cols'];
                  $leftPct = $widthPct * $item['col'];
                ?>
                  <a href="event_form.php?id=<?= (int) $ev['id'] ?>&back=<?= urlencode($back) ?>"
                     class="week-event<?= $isFamily ? ' family-event' : '' ?>"
                     style="<?= $isFamily ? '' : 'background:' . h($color) . ';' ?>top: calc(var(--week-hour-height) * <?= $topFactor ?>); height: calc(var(--week-hour-height) * <?= $heightFactor ?> - 2px); left: calc(<?= $leftPct ?>% + 1px); width: calc(<?= $widthPct ?>% - 2px);">
                    <span class="week-event-time"><?= h(substr($ev['starts_at'], 11, 5)) ?></span>
                    <span class="week-event-title"><?= $isFamily ? '👪 ' : '' ?><?= h($ev['title']) ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>

    <?php else: ?>

      <div class="calendar-grid">
        <?php foreach ($weekdayLabels as $wd): ?>
          <div class="calendar-weekday"><?= $wd ?></div>
        <?php endforeach; ?>

        <?php for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')):
          $dayStr = $day->format('Y-m-d');
          $isOtherMonth = $day->format('Y-m') !== $monthStart->format('Y-m');
          $isToday = $dayStr === $today;
          $dayEvents = $eventsByDay[$dayStr] ?? [];
          $dayTasks = $tasksByDay[$dayStr] ?? [];
        ?>
          <div class="calendar-day<?= $isOtherMonth ? ' other-month' : '' ?><?= $isToday ? ' today' : '' ?>">
            <div class="day-header">
              <a class="day-number" href="<?= h(buildCalendarUrl('day', $monthParam, $dayStr, $hiddenIds)) ?>"><?= (int) $day->format('j') ?></a>
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
            <?php foreach ($dayTasks as $t): ?>
              <a href="task_list_view.php?id=<?= (int) $t['task_list_id'] ?>" class="task-chip" title="Aufgabe aus „<?= h($t['task_list_name']) ?>“">
                ☐ <?= h($t['title']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endfor; ?>
      </div>

    <?php endif; ?>

    <a class="mobile-fab" href="event_form.php?date=<?= h($dateParam) ?>&back=<?= urlencode($back) ?>" aria-label="Neuer Termin">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </a>
<?= appChromeClose('calendar') ?>
</body>
</html>
