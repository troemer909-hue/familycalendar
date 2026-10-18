<?php

declare(strict_types=1);

// Session-Cookie-Lebensdauer: an Token::LONG_LIVED_SECONDS angeglichen -
// Nutzer sollen dauerhaft angemeldet bleiben, nicht alle paar Wochen neu
// einloggen müssen (Literal statt Konstante, weil dieser Codeblock VOR
// dem Laden der Klassen laufen muss, siehe session_start() unten).
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 365 * 10,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/apiRoutes.php';
require_once __DIR__ . '/LocalApiClient.php';

// Für LocalApiClient nötig (Datenbank-Zugangsdaten, APP_SECRET für
// Token::verify() etc.) - bisher lud nur public/api.php die .env-Datei,
// weil Client-Seiten nie direkt auf Datenbank/Token zugegriffen haben
// (siehe LocalApiClient-Klassendoc). Env::load() ist idempotent, ein
// zweiter Aufruf aus public/api.php selbst bleibt also folgenlos.
\Familienkalender\Core\Env::load(dirname(__DIR__) . '/.env');

// In diesem zusammengeführten Setup liegen API (/api/...) und Client
// (login.php, calendar.php, ...) auf DERSELBEN Domain. Hier die eigene
// Domain eintragen, OHNE /api am Ende (das hängt ApiClient selbst an),
// z.B. 'https://kalender.eastcon.eu'. Dann ist das Feld auf der
// Login-/Registrierungsseite automatisch vorausgefüllt.
const DEFAULT_API_BASE_URL = '';

/**
 * Leitet die API-Basis-URL aus dem aktuellen Request her, wenn
 * DEFAULT_API_BASE_URL nicht gesetzt ist - in diesem zusammengeführten
 * Setup liegen Client und API immer auf derselben Domain (siehe oben), das
 * manuelle Eintragen der "API-Adresse" bei jedem Login war daher reine
 * Reibung ohne echten Nutzen. $_SERVER['HTTP_HOST'] kommt vom Client und
 * wird NICHT für sicherheitsrelevante Entscheidungen verwendet (nur um die
 * URL für den eigenen nachgelagerten API-Call zusammenzubauen, der ohnehin
 * serverseitig authentifiziert/validiert wird).
 */
function defaultApiBaseUrl(): string
{
    if (DEFAULT_API_BASE_URL !== '') {
        return DEFAULT_API_BASE_URL;
    }
    if (empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'];
}

/**
 * Wie defaultApiBaseUrl(), erlaubt aber weiterhin ein explizites
 * api_base_url-POST-Feld als Override - das Feld selbst ist aus den
 * Formularen entfernt (siehe login.php etc.), normale Nutzer senden es
 * also nie mehr mit. Die Integration-Testsuite (siehe tests/Support/
 * FrontendServer.php) braucht den Override aber weiterhin: dort laufen
 * Client-Seiten und API bewusst auf ZWEI getrennten PHP-Built-in-Server-
 * Prozessen (ein `php -S`-Prozess bearbeitet nur einen Request
 * gleichzeitig - ein ausgehender Call von calendar.php auf sich selbst
 * würde sonst blockieren, bis er in Timeout läuft), und muss calendar.php
 * & Co. explizit auf den separaten API-Prozess zeigen lassen, statt auf
 * den eigenen Host, von dem der Request kam.
 */
function resolveApiBaseUrl(): string
{
    $posted = trim((string) ($_POST['api_base_url'] ?? ''));
    return $posted !== '' ? $posted : defaultApiBaseUrl();
}

/**
 * Auth-Daten sind bewusst NICHT mehr in einer gemeinsamen Datei gespeichert
 * (das würde auf einem von mehreren Personen genutzten Server dazu führen,
 * dass sich alle gegenseitig ein-/ausloggen), sondern in der PHP-Session –
 * jeder Browser/jedes Gerät bekommt dadurch sein eigenes, isoliertes Login.
 * Die Funktionsnamen bleiben unverändert, damit der Rest der App unangetastet
 * bleibt.
 */
function loadConfig(): array
{
    return [
        'api_base_url' => $_SESSION['api_base_url'] ?? DEFAULT_API_BASE_URL,
        'token' => $_SESSION['token'] ?? null,
        'is_platform_admin' => $_SESSION['is_platform_admin'] ?? false,
    ];
}

function saveConfig(array $config): void
{
    if (array_key_exists('api_base_url', $config)) {
        $_SESSION['api_base_url'] = $config['api_base_url'];
    }
    if (array_key_exists('token', $config)) {
        $_SESSION['token'] = $config['token'];
    }
    if (array_key_exists('is_platform_admin', $config)) {
        $_SESSION['is_platform_admin'] = $config['is_platform_admin'];
    }
}

/**
 * Nur fürs client-seitige Ein-/Ausblenden des "Plattform-Verwaltung"-Menüpunkts
 * (siehe AuthController::login) - KEIN Sicherheitsmechanismus, die eigentliche
 * Durchsetzung passiert serverseitig in TenantContext::requirePlatformAdmin().
 */
function isPlatformAdmin(): bool
{
    return (bool) loadConfig()['is_platform_admin'];
}

/**
 * Wählt zwischen LocalApiClient (Normalfall: Client-Seite und API laufen
 * im selben PHP-Prozess - direkter In-Process-Aufruf statt HTTP-Self-Call,
 * siehe LocalApiClient-Klassendoc) und ApiClient (echter HTTP-Call, nötig
 * wenn $apiBaseUrl explizit von der eigenen Domain abweicht - aktuell
 * ausschließlich die Integrationstest-Infrastruktur, siehe
 * resolveApiBaseUrl()). Der Vergleich gegen defaultApiBaseUrl() erkennt
 * genau diesen Fall, ohne dass die Aufrufer (currentApiClient() unten,
 * die *_submit.php-Skripte) das Unterscheidungskriterium selbst kennen
 * müssen.
 */
function apiClientFor(string $apiBaseUrl, ?string $token): ApiClient|LocalApiClient
{
    if ($apiBaseUrl !== '' && $apiBaseUrl !== defaultApiBaseUrl()) {
        return new ApiClient($apiBaseUrl, $token);
    }

    return new LocalApiClient($token);
}

function currentApiClient(): ApiClient|LocalApiClient
{
    $config = loadConfig();
    return apiClientFor((string) ($config['api_base_url'] ?? ''), $config['token'] ?? null);
}

function requireLogin(): void
{
    $config = loadConfig();
    if (empty($config['token'])) {
        header('Location: login.php');
        exit;
    }
}

/** Bei 401 vom Backend (Token abgelaufen/ungültig) sauber zur Anmeldung zurück. */
function handleUnauthorized(array $apiResult): void
{
    if ($apiResult['status'] === 401) {
        saveConfig(['token' => null]);
        header('Location: login.php?error=' . urlencode('Bitte erneut anmelden (Sitzung abgelaufen).'));
        exit;
    }
}

/**
 * Bei 403 (z.B. kein Plattform-Admin, siehe admin_*.php) zurück zum
 * Kalender statt die Seite mit einer rohen API-Fehlermeldung zu zeigen -
 * die Admin-Seiten verlinken bewusst für alle eingeloggten Nutzer (die
 * eigentliche Durchsetzung passiert serverseitig in TenantContext::
 * requirePlatformAdmin()), ein Klick ohne Berechtigung landet so einfach
 * mit einer verständlichen Meldung zurück auf der Startseite.
 */
function handleForbidden(array $apiResult): void
{
    if ($apiResult['status'] === 403) {
        header('Location: calendar.php?error=' . urlencode($apiResult['data']['error'] ?? 'Kein Zugriff.'));
        exit;
    }
}

/** Vergleicht das übermittelte gegen das in der Session hinterlegte CSRF-Token. */
function csrfTokenValid(?string $submitted): bool
{
    $expected = $_SESSION['csrf_token'] ?? null;
    return is_string($submitted) && is_string($expected) && $submitted !== '' && hash_equals($expected, $submitted);
}

/**
 * Zentrale CSRF-Prüfung für klassische Formular-POST-Handler. Muss als
 * ERSTES im Handler aufgerufen werden, bevor irgendeine Aktion gegen die
 * API läuft. Das erwartete Token kommt aus csrfToken()/csrfField() (siehe
 * Helpers.php), die in jedes Formular ein verstecktes Feld einbetten.
 */
function requireCsrf(): void
{
    if (csrfTokenValid($_POST['csrf_token'] ?? null)) {
        return;
    }

    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<title>Sicherheitsprüfung fehlgeschlagen</title>'
        . '<link rel="stylesheet" href="styles.css" /></head><body>'
        . '<div class="main-area"><div class="form-card">'
        . '<h1>Sicherheitsprüfung fehlgeschlagen</h1>'
        . '<p class="error-box">Das Formular ist abgelaufen oder ungültig (CSRF-Token fehlt/stimmt nicht). '
        . 'Bitte zurückgehen und erneut versuchen.</p>'
        . '<p><a href="javascript:history.back()" class="btn btn-secondary">Zurück</a></p>'
        . '</div></div></body></html>';
    exit;
}

/**
 * Dieselbe Prüfung für die fetch()-basierten JSON-Endpunkte (Push-Abo) -
 * dort liegt das Token im decodierten Request-Body statt in $_POST, und
 * eine JSON-Fehlermeldung passt besser zum Rest der Antwort als HTML.
 */
function requireCsrfJson(array $body): void
{
    $submitted = $body['csrf_token'] ?? null;
    if (csrfTokenValid(is_string($submitted) ? $submitted : null)) {
        return;
    }

    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Sicherheitsprüfung fehlgeschlagen (CSRF-Token fehlt/stimmt nicht).']);
    exit;
}
