<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Gemeinsame <head>-Tags, damit die App auf dem Smartphone installierbar
 * ist (Homescreen-Icon, Standalone-Fenster ohne Browser-UI) - auf jeder
 * Seite eingebunden, da der Browser das Manifest unabhängig vom
 * Einstiegspunkt lesen muss. Der eigentliche Installations-/Offline-
 * Mechanismus steckt in public/manifest.json und public/sw.js.
 */
function pwaHeadTags(): string
{
    return <<<'HTML'
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#203650" />
  <link rel="manifest" href="/manifest.json" />
  <link rel="icon" href="/icons/icon-192.png" />
  <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-title" content="Family-Kalender" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,380;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800&display=swap" />
HTML;
}

/**
 * Logo-Mark (Kalender-Icon) + Wortmarke "Family-Kalender" als Inline-SVG -
 * identische Pfade/Farbwerte wie auf der Marketing-Webseite
 * (family-kalender.de), damit das Branding zwischen Marketing-Seite und
 * App konsistent ist. Als <symbol>+<use> statt direktem Pfad-Duplikat,
 * damit mehrere Aufrufe auf derselben Seite (z.B. Sidebar + mobiles
 * Menü) nicht unnötig aufblähen - die <defs> werden pro Seite einmalig
 * über $alreadyRendered ausgegeben.
 *
 * @param string $extraClass zusätzliche CSS-Klasse auf dem äußeren <a>,
 *   z.B. um die Größe je nach Einsatzort (Sidebar vs. Login-Seite) zu steuern.
 * @param string $href Linkziel - "calendar.php" für eingeloggte Seiten,
 *   "login.php" für die Anmelde-/Registrierungs-Seiten selbst.
 */
function brandLogo(string $extraClass = '', string $href = 'calendar.php'): string
{
    static $defsRendered = false;

    $defs = '';
    if (!$defsRendered) {
        $defsRendered = true;
        $defs = <<<'SVG'
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <linearGradient id="fkWarmGrad" x1="0" y1="0" x2="1" y2="0">
      <stop stop-color="#ff9d28"/><stop offset=".55" stop-color="#f45155"/><stop offset="1" stop-color="#ef3b59"/>
    </linearGradient>
    <linearGradient id="fkCoolGrad" x1="0" y1="0" x2="1" y2="1">
      <stop stop-color="#29b5bd"/><stop offset="1" stop-color="#177fa1"/>
    </linearGradient>
    <symbol id="fkCalIcon" viewBox="0 0 200 200">
      <rect x="18" y="20" width="164" height="162" rx="22" fill="#fff"/>
      <path d="M18 43Q18 20 41 20h118q23 0 23 23v20H18z" fill="url(#fkWarmGrad)"/>
      <path d="M57 5v23M143 5v23" stroke="#203650" stroke-width="11" stroke-linecap="round"/>
      <path d="M57 21v12M143 21v12" stroke="#fff" stroke-width="17" stroke-linecap="round"/>
      <path d="M57 21v12M143 21v12" stroke="#203650" stroke-width="9" stroke-linecap="round"/>
      <path d="M42 96h20m72-20h22m-75-1h11m56 68h14M39 151h11" stroke="#bfd0dc" stroke-width="5" stroke-linecap="round"/>
      <circle cx="79" cy="82" r="12" fill="url(#fkWarmGrad)"/>
      <circle cx="122" cy="72" r="13" fill="url(#fkCoolGrad)"/>
      <circle cx="150" cy="94" r="7" fill="url(#fkWarmGrad)"/>
      <circle cx="40" cy="169" r="7" fill="url(#fkCoolGrad)"/>
      <path d="M82 150c-26-4-38-20-31-34 6-11 23-13 34-4" fill="none" stroke="url(#fkWarmGrad)" stroke-width="12" stroke-linecap="round"/>
      <circle cx="103" cy="122" r="22" fill="none" stroke="url(#fkCoolGrad)" stroke-width="11"/>
      <circle cx="103" cy="123" r="8" fill="url(#fkWarmGrad)"/>
      <path d="M85 140c-2-13 7-20 18-20 13 0 21 9 20 21v30H91v-19c-10-5-17-11-20-19" fill="url(#fkCoolGrad)"/>
      <circle cx="83" cy="133" r="9" fill="url(#fkCoolGrad)"/>
      <path d="M119 145c18-5 29-17 37-27 4-5 11 0 8 6-7 15-16 25-27 31v16h-30v-12c1-7 5-11 12-14z" fill="url(#fkWarmGrad)"/>
    </symbol>
  </defs>
</svg>
SVG;
    }

    $class = h(trim('brand ' . $extraClass));
    $hrefAttr = h($href);

    // Die Wortmarke wird bewusst als normaler HTML-Text (statt als
    // <text> innerhalb des SVG) gerendert: SVG-<text> in sehr kleinen
    // Zielgrößen (Sidebar) führte je nach Font-Stack/Browser zu
    // abgeschnittenem Text (Hinting-/Kerning-Eigenheiten bei winzigen
    // SVG-Textgrößen) - echter DOM-Text skaliert dagegen zuverlässig
    // mit font-size und ist zusätzlich besser für Screenreader.
    return $defs . <<<HTML
<a href="{$hrefAttr}" class="{$class}">
  <svg class="mark" viewBox="0 0 200 200" aria-hidden="true"><use href="#fkCalIcon"/></svg>
  <span class="word">Family-<span class="word-teal">Kalen</span><span class="word-orange">der</span></span>
</a>
HTML;
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
