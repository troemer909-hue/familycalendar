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
  <!-- Nicht-blockierendes Nachladen: media="print" verhindert, dass der
       Browser auf diese externe Schriftart warten muss, bevor er
       überhaupt etwas zeigt (vorher ein render-blockierender <link
       rel="stylesheet">, der jede Seite auf eine Antwort von
       fonts.googleapis.com warten ließ). onload schaltet auf "all" um,
       sobald die Datei da ist; <noscript> sichert den Fall ohne JS ab. -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,380;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800&display=swap" media="print" onload="this.media='all'" />
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,380;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800&display=swap" /></noscript>
  <script>
    // Chrome/Android verlangt für den automatischen "App installieren"-
    // Hinweis (beforeinstallprompt) einen bereits registrierten, aktiven
    // Service Worker - vorher wurde der nur auf push_subscribe.php
    // registriert, die kaum jemand besucht, bevor er/sie die App
    // installieren will. Hier auf JEDER Seite registriert, damit die
    // Installierbarkeits-Kriterien schon beim ersten Besuch erfüllt sind.
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').catch(function () {
          // Kein harter Fehler - ohne Service Worker läuft die App normal
          // weiter, nur die Installierbarkeit als PWA fehlt dann.
        });
      });
    }

    // Chrome zeigt den automatischen Installations-Hinweis nur nach
    // eigenen Engagement-Heuristiken (u.a. mehrfacher Besuch mit
    // zeitlichem Abstand) - das Ereignis selbst (beforeinstallprompt)
    // kommt aber zuverlässig, sobald die Installierbarkeits-Kriterien
    // erfüllt sind. Hier abgefangen und für einen eigenen, sofort
    // sichtbaren "App installieren"-Button in der Sidebar aufgehoben
    // (siehe appChromeOpen()/appChromeClose()), statt auf den
    // Browser-Zufall zu warten.
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      window.__fkInstallPrompt = e;
      document.dispatchEvent(new CustomEvent('fk:install-available'));
    });
    window.addEventListener('appinstalled', function () {
      window.__fkInstallPrompt = null;
      document.dispatchEvent(new CustomEvent('fk:install-done'));
    });
  </script>
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

/**
 * Gemeinsame "App-Chrome" (Hamburger-Topbar + Sidebar-Drawer + mobile
 * Footer-Tabbar) für ALLE eingeloggten App-Seiten - vorher steckte das
 * nur in calendar.php, wodurch das Footer-Menü beim Aufruf jeder anderen
 * Seite (Mitglieder, Kalenderquellen, Einstellungen, ...) verschwand.
 * $activeNav steuert, welcher Sidebar-/Footer-Eintrag als aktiv markiert
 * wird: 'calendar' | 'members' | 'sources' | 'ics' | 'push' |
 * 'notifications' | 'admin'. $extraSidebarHtml ist zusätzlicher Inhalt
 * ganz oben in der Sidebar (aktuell nur von calendar.php für den
 * Mitglieder-Sichtbarkeitsfilter genutzt). $mainAttrs sind zusätzliche
 * Attribute auf dem <main>-Tag (aktuell nur von calendar.php für die
 * Wischgesten-Datenattribute genutzt).
 *
 * appChromeOpen() öffnet <div class="app-layout"> bis <main ...> (noch
 * offen!), appChromeClose() schließt </main></div>, hängt die
 * Footer-Tabbar an und bindet das Drawer-/Wischgesten-Script ein - jede
 * Seite ruft beide auf und füllt dazwischen ihren eigenen Inhalt.
 */
function appChromeOpen(string $activeNav, string $extraSidebarHtml = '', string $mainAttrs = ''): string
{
    $navClass = static fn (string $key): string => 'nav-link' . ($activeNav === $key ? ' active' : '');

    ob_start();
    ?>
<div class="app-layout">
  <header class="mobile-topbar">
    <button type="button" class="sidebar-toggle" data-sidebar-trigger aria-label="Menü öffnen" aria-expanded="false" aria-controls="app-sidebar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <?= brandLogo() ?>
  </header>
  <div class="sidebar-backdrop" data-sidebar-backdrop></div>
  <aside class="sidebar" id="app-sidebar">
    <?= brandLogo() ?>
    <button type="button" id="fk-install-btn" class="btn btn-secondary install-btn" hidden>
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0 0-4-4m4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
      App installieren
    </button>
    <?= $extraSidebarHtml ?>

    <nav class="sidebar-nav">
      <div class="nav-group">
        <span class="nav-group-label">Kalender</span>
        <a href="members.php" class="<?= $navClass('members') ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 5 18.5V20"/><circle cx="9.5" cy="8" r="3.2"/><path d="M15.5 8.2a2.8 2.8 0 1 1 1.9 5"/><path d="M19.5 20v-1.3a3 3 0 0 0-2-2.8"/></svg>
          <span>Mitglieder verwalten</span>
        </a>
        <a href="calendar_sources.php" class="<?= $navClass('sources') ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 12a8 8 0 0 1 14.6-4.6M20 12a8 8 0 0 1-14.6 4.6"/><path d="M18.6 3.4V8h-4.6M5.4 20.6V16H10"/></svg>
          <span>Kalenderquellen verwalten</span>
        </a>
        <a href="categories.php" class="<?= $navClass('categories') ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h4v4H4zM10 7h10M4 13h4v4H4zM10 13h10"/></svg>
          <span>Kategorien</span>
        </a>
        <a href="ics_export.php" class="<?= $navClass('ics') ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0 0-4-4m4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
          <span>Kalender-Abo (ICS-Export)</span>
        </a>
      </div>

      <div class="nav-group">
        <span class="nav-group-label">Benachrichtigungen</span>
        <a href="push_subscribe.php" class="<?= $navClass('push') ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 1 0-12 0c0 4-1.5 6-2 7h16c-.5-1-2-3-2-7"/><path d="M10 19a2 2 0 0 0 4 0"/></svg>
          <span>Push-Benachrichtigungen</span>
        </a>
        <a href="notification_settings.php" class="<?= $navClass('notifications') ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 13.5a7.6 7.6 0 0 0 0-3l1.8-1.4-2-3.4-2.1.7a7.7 7.7 0 0 0-2.6-1.5L14 2.5h-4l-.5 2.4a7.7 7.7 0 0 0-2.6 1.5l-2.1-.7-2 3.4L4.6 10.5a7.6 7.6 0 0 0 0 3l-1.8 1.4 2 3.4 2.1-.7a7.7 7.7 0 0 0 2.6 1.5l.5 2.4h4l.5-2.4a7.7 7.7 0 0 0 2.6-1.5l2.1.7 2-3.4z"/></svg>
          <span>Benachrichtigungseinstellungen</span>
        </a>
      </div>

      <?php if (isPlatformAdmin()): ?>
      <div class="nav-group">
        <span class="nav-group-label">Verwaltung</span>
        <a href="admin_families.php" class="<?= $navClass('admin') ?>">
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
        <p class="legal-links">
          <a href="impressum.php">Impressum</a><span class="sep">·</span><a href="datenschutz.php">Datenschutz</a>
        </p>
      </div>
    </nav>
  </aside>

  <main class="main-area" <?= $mainAttrs ?>>
    <?php
    return ob_get_clean();
}

function appChromeClose(string $activeNav): string
{
    $bottomActive = in_array($activeNav, ['calendar', 'members', 'sources'], true) ? $activeNav : 'more';
    $itemClass = static fn (string $key): string => 'bottom-nav-item' . ($bottomActive === $key ? ' active' : '');

    ob_start();
    ?>
  </main>
</div>

<nav class="mobile-bottom-nav">
  <a href="calendar.php" class="<?= $itemClass('calendar') ?>">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
    <span>Kalender</span>
  </a>
  <a href="members.php" class="<?= $itemClass('members') ?>">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 5 18.5V20"/><circle cx="9.5" cy="8" r="3.2"/><path d="M15.5 8.2a2.8 2.8 0 1 1 1.9 5"/><path d="M19.5 20v-1.3a3 3 0 0 0-2-2.8"/></svg>
    <span>Familie</span>
  </a>
  <a href="calendar_sources.php" class="<?= $itemClass('sources') ?>">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 12a8 8 0 0 1 14.6-4.6M20 12a8 8 0 0 1-14.6 4.6"/><path d="M18.6 3.4V8h-4.6M5.4 20.6V16H10"/></svg>
    <span>Quellen</span>
  </a>
  <button type="button" class="<?= $itemClass('more') ?>" data-sidebar-trigger aria-label="Menü öffnen">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="6" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="18" r="1.6"/></svg>
    <span>Mehr</span>
  </button>
</nav>

<script>
  // Mobile Sidebar als Off-Canvas-Drawer: nur relevant auf schmalen
  // Bildschirmen (siehe @media (max-width: 860px) in styles.css), auf
  // dem Desktop bleibt die Sidebar immer sichtbar und dieser Code
  // bleibt wirkungslos (kein "open"-Klasse-Pfad wird dort erreicht).
  (function () {
    // Zwei Trigger öffnen denselben Drawer: der Hamburger in der
    // Topbar (nur bei engem Viewport sichtbar) und der "Mehr"-Eintrag
    // im Footer-Menü - beide tragen [data-sidebar-trigger].
    var triggers = document.querySelectorAll('[data-sidebar-trigger]');
    var sidebar = document.getElementById('app-sidebar');
    var backdrop = document.querySelector('[data-sidebar-backdrop]');
    if (!triggers.length || !sidebar || !backdrop) return;

    function setExpanded(value) {
      triggers.forEach(function (t) { t.setAttribute('aria-expanded', value); });
    }
    function closeSidebar() {
      sidebar.classList.remove('open');
      backdrop.classList.remove('visible');
      setExpanded('false');
    }
    function openSidebar() {
      sidebar.classList.add('open');
      backdrop.classList.add('visible');
      setExpanded('true');
    }
    triggers.forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        if (sidebar.classList.contains('open')) {
          closeSidebar();
        } else {
          openSidebar();
        }
      });
    });
    backdrop.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeSidebar);
    });
  })();

  // "App installieren"-Button in der Sidebar: bleibt per [hidden]
  // unsichtbar, bis beforeinstallprompt gefeuert hat (siehe
  // pwaHeadTags()) - Chrome entscheidet selbst, WANN das passiert
  // (Engagement-Heuristiken), aber sobald es so weit ist, zeigen wir den
  // Button sofort statt auf Chromes eigenes (seltener erscheinendes)
  // Banner zu warten.
  (function () {
    var btn = document.getElementById('fk-install-btn');
    if (!btn) return;

    function reveal() {
      if (window.__fkInstallPrompt) {
        btn.hidden = false;
      }
    }
    reveal(); // falls beforeinstallprompt schon VOR diesem Script feuerte
    document.addEventListener('fk:install-available', reveal);
    document.addEventListener('fk:install-done', function () { btn.hidden = true; });

    btn.addEventListener('click', function () {
      if (!window.__fkInstallPrompt) return;
      window.__fkInstallPrompt.prompt();
      window.__fkInstallPrompt.userChoice.finally(function () {
        window.__fkInstallPrompt = null;
        btn.hidden = true;
      });
    });
  })();

  // Wischgeste (links/rechts) zum Wechseln zum vorherigen/nächsten
  // Monat, Woche oder Tag - wie in Google Kalender. Nur auf Seiten mit
  // [data-swipe-nav] (aktuell nur calendar.php) überhaupt aktiv, auf
  // allen anderen Seiten bleibt dieser Code wirkungslos. Reagiert nur
  // auf überwiegend horizontale Wischbewegungen über einer
  // Mindestdistanz, damit normales vertikales Scrollen unangetastet
  // bleibt (kein preventDefault auf touchmove).
  (function () {
    var area = document.querySelector('[data-swipe-nav]');
    if (!area) return;
    var startX = null;
    var startY = null;
    var THRESHOLD = 60;

    area.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) return;
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
    }, { passive: true });

    area.addEventListener('touchend', function (e) {
      if (startX === null) return;
      var endX = e.changedTouches[0].clientX;
      var endY = e.changedTouches[0].clientY;
      var dx = endX - startX;
      var dy = endY - startY;
      startX = null;
      if (Math.abs(dx) < THRESHOLD || Math.abs(dx) < Math.abs(dy) * 1.5) return;
      var url = dx < 0 ? area.dataset.nextUrl : area.dataset.prevUrl;
      if (url) window.location.href = url;
    }, { passive: true });
  })();
</script>
    <?php
    return ob_get_clean();
}
