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

/** Baut die aktuelle Query-String-Basis (für Filter-Links auf calendar.php) neu zusammen. */
function buildCalendarUrl(string $month, array $hiddenMemberIds): string
{
    $params = ['month' => $month];
    if (!empty($hiddenMemberIds)) {
        $params['hide'] = implode(',', $hiddenMemberIds);
    }
    return 'calendar.php?' . http_build_query($params);
}
