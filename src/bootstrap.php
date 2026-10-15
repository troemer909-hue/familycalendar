<?php

declare(strict_types=1);

// Session-Cookie-Lebensdauer: 30 Tage, damit man nicht bei jedem Browser-
// Neustart neu einloggen muss (an die Token-Gültigkeit im Backend angeglichen).
// Muss VOR session_start() gesetzt werden.
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Helpers.php';

// In diesem zusammengeführten Setup liegen API (/api/...) und Client
// (login.php, calendar.php, ...) auf DERSELBEN Domain. Hier die eigene
// Domain eintragen, OHNE /api am Ende (das hängt ApiClient selbst an),
// z.B. 'https://kalender.eastcon.eu'. Dann ist das Feld auf der
// Login-/Registrierungsseite automatisch vorausgefüllt.
const DEFAULT_API_BASE_URL = '';

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
}

function currentApiClient(): ApiClient
{
    $config = loadConfig();
    return new ApiClient($config['api_base_url'] ?? '', $config['token'] ?? null);
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
