<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Familienkalender\Controllers\AlexaOAuthController;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;

set_exception_handler(static function (Throwable $e): void {
    error_log('Alexa OAuth error: ' . $e->getMessage());
    Response::json(['error' => 'server_error'], 500);
});

$controller = new AlexaOAuthController();
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($path === '/oauth/authorize' && in_array($method, ['GET', 'POST'], true)) {
    $controller->authorize(new Request());
}
if ($path === '/oauth/token' && $method === 'POST') {
    $controller->token(new Request());
}
Response::error('Route nicht gefunden.', 404);
