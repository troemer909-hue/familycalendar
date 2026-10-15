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
