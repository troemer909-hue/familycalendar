<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Familienkalender\Controllers\AlexaOAuthController;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// /oauth/authorize rendert HTML (Login-/Consent-Formular) für einen
// Browser, /oauth/token liefert JSON für Amazons Server-zu-Server-Aufruf -
// ein unbehandelter Fehler muss dasselbe Format treffen wie die Route
// selbst, sonst sieht ein Nutzer mitten im Login-Flow rohes JSON statt
// einer Fehlerseite.
set_exception_handler(static function (Throwable $e) use ($path): void {
    error_log('Alexa OAuth error: ' . $e->getMessage());
    if ($path === '/oauth/authorize') {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="de"><meta charset="utf-8">'
            . '<title>Alexa-Verknüpfung nicht möglich</title><link rel="stylesheet" href="/styles.css">'
            . '<main class="auth-content"><section class="login-card"><h1>Alexa-Verknüpfung nicht möglich</h1>'
            . '<p class="error-box">Es ist ein unerwarteter Fehler aufgetreten. Bitte versuche es später erneut.</p>'
            . '</section></main></html>';
        exit;
    }
    Response::json(['error' => 'server_error'], 500);
});

$controller = new AlexaOAuthController();
if ($path === '/oauth/authorize' && in_array($method, ['GET', 'POST'], true)) {
    $controller->authorize(new Request());
}
if ($path === '/oauth/token' && $method === 'POST') {
    $controller->token(new Request());
}
Response::error('Route nicht gefunden.', 404);
