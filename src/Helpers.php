<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * CSRF-Schutz für die POST-Formulare dieses (session-basierten) Clients.
 * Ein Token pro Session reicht aus - es wird bei der ersten Verwendung
 * erzeugt und bleibt über die gesamte Session gültig (auch über
 * session_regenerate_id(true) nach dem Login hinweg, da dabei die
 * $_SESSION-Daten erhalten bleiben). Geprüft wird es zentral in
 * requireCsrf() (siehe bootstrap.php).
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Fertiges Hidden-Field zum Einbetten in <form method="post">. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '" />';
}

function formatGermanMonth(\DateTimeImmutable $date): string
{
    $months = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];
    return $months[(int) $date->format('n')] . ' ' . $date->format('Y');
}

/** "2026-10-03 10:00:00" -> "03.10.2026 10:00" */
function formatGermanDateTime(string $mysqlDatetime): string
{
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime);
    return $dt ? $dt->format('d.m.Y H:i') : $mysqlDatetime;
}

/** Farbe für einen Termin-Chip: Familientermine grau, sonst Farbe des ersten Teilnehmers. */
function eventColor(array $event, array $membersById): string
{
    if ($event['scope'] === 'family') {
        return '#64748b';
    }
    if (!empty($event['participant_ids']) && isset($membersById[$event['participant_ids'][0]])) {
        return $membersById[$event['participant_ids'][0]]['display_color'];
    }
    return '#64748b';
}

/** "2026-03-10" -> "Dienstag, 10. März 2026" (Tagesansicht-Überschrift). */
function formatGermanFullDate(\DateTimeImmutable $date): string
{
    $weekdays = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
    return $weekdays[(int) $date->format('N')] . ', ' . (int) $date->format('j') . '. ' . formatGermanMonth($date);
}

/**
 * Die drei Kalenderansichten (siehe calendar.php) teilen sich dieselben
 * drei Filter/Navigations-Parameter - nur je nach Ansicht ist "month"
 * ODER "date" der maßgebliche Anker. Zentral an einer Stelle gebaut,
 * damit Navigationslinks UND das "back"-Feld der Termin-/Erinnerungs-
 * formulare garantiert dasselbe Format erzeugen.
 *
 * @param list<int> $hiddenMemberIds
 * @return array<string, string>
 */
function calendarQueryParams(string $view, string $month, string $date, array $hiddenMemberIds): array
{
    $view = in_array($view, ['month', 'week', 'day'], true) ? $view : 'month';
    $params = ['view' => $view];
    if ($view === 'month') {
        $params['month'] = $month;
    } else {
        $params['date'] = $date;
    }
    if (!empty($hiddenMemberIds)) {
        $params['hide'] = implode(',', $hiddenMemberIds);
    }
    return $params;
}

/** Baut die aktuelle Query-String-Basis (für Filter-/Navigationslinks auf calendar.php) neu zusammen. */
function buildCalendarUrl(string $view, string $month, string $date, array $hiddenMemberIds): string
{
    return 'calendar.php?' . http_build_query(calendarQueryParams($view, $month, $date, $hiddenMemberIds));
}

/**
 * Validiert/normalisiert den "back"-Parameter, mit dem Termin- und
 * Erinnerungsformulare sich merken, in welche Kalenderansicht sie nach
 * dem Speichern/Löschen zurückspringen sollen. Kommt als rohe
 * Query-String-Zeichenkette aus einem Formularfeld bzw. Linkparameter -
 * also potenziell vom Nutzer manipulierbar - und wird deshalb nicht
 * direkt in eine Redirect-URL übernommen, sondern über
 * calendarQueryParams() neu und sauber zusammengesetzt.
 */
function sanitizeCalendarBack(string $back): string
{
    parse_str($back, $parsed);

    $view = is_string($parsed['view'] ?? null) ? $parsed['view'] : 'month';
    $month = is_string($parsed['month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $parsed['month'])
        ? $parsed['month']
        : date('Y-m');
    $date = is_string($parsed['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed['date'])
        ? $parsed['date']
        : date('Y-m-d');

    $hiddenMemberIds = [];
    if (is_string($parsed['hide'] ?? null) && preg_match('/^\d+(,\d+)*$/', $parsed['hide'])) {
        $hiddenMemberIds = array_map('intval', explode(',', $parsed['hide']));
    }

    return http_build_query(calendarQueryParams($view, $month, $date, $hiddenMemberIds));
}
