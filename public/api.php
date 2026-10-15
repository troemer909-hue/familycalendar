<?php

declare(strict_types=1);

// ---------------------------------------------------------------------
// Schlanker PSR-4-Autoloader – bewusst ohne "composer install", damit
// das Grundgerüst ohne Internetzugriff sofort lauffähig ist. Sobald
// composer verfügbar ist, kann dies 1:1 durch vendor/autoload.php
// ersetzt werden (siehe composer.json).
// ---------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'Familienkalender\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Familienkalender\Controllers\AuthController;
use Familienkalender\Controllers\CalendarSourceController;
use Familienkalender\Controllers\EventController;
use Familienkalender\Controllers\MemberController;
use Familienkalender\Controllers\ReminderController;
use Familienkalender\Core\Env;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\Router;
use Familienkalender\Middleware\AuthMiddleware;

Env::load(dirname(__DIR__) . '/.env');

// CORS: für lokale Entwicklung offen, in Produktion auf die eigene
// PWA-Domain einschränken (ENV: APP_ALLOWED_ORIGIN).
header('Access-Control-Allow-Origin: ' . (Env::get('APP_ALLOWED_ORIGIN', '*')));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error('Interner Fehler.', 500);
});

$request = new Request();
$router = new Router();
$authMiddleware = new AuthMiddleware();

// -- Auth (öffentlich) --------------------------------------------------
$auth = new AuthController();
$router->post('/api/auth/register', [$auth, 'register']);
$router->post('/api/auth/login', [$auth, 'login']);

// -- Mitglieder (geschützt) ----------------------------------------------
$members = new MemberController();
$router->get('/api/members', [$members, 'index'], [$authMiddleware]);
$router->post('/api/members', [$members, 'createProfile'], [$authMiddleware]);
$router->post('/api/members/{id}/upgrade', [$members, 'upgrade'], [$authMiddleware]);
$router->delete('/api/members/{id}/login', [$members, 'downgrade'], [$authMiddleware]);

// -- Termine (geschützt) --------------------------------------------------
$events = new EventController();
$router->get('/api/events', [$events, 'index'], [$authMiddleware]);
$router->get('/api/events/{id}', [$events, 'show'], [$authMiddleware]);
$router->post('/api/events', [$events, 'create'], [$authMiddleware]);
$router->put('/api/events/{id}', [$events, 'update'], [$authMiddleware]);
$router->delete('/api/events/{id}', [$events, 'delete'], [$authMiddleware]);
$router->delete('/api/events/{id}/series', [$events, 'deleteSeries'], [$authMiddleware]);

// -- Erinnerungen (geschützt, persönlich je Login-Nutzer) ---------------
$reminders = new ReminderController();
$router->get('/api/events/{id}/reminders', [$reminders, 'index'], [$authMiddleware]);
$router->post('/api/events/{id}/reminders', [$reminders, 'create'], [$authMiddleware]);
$router->delete('/api/reminders/{id}', [$reminders, 'destroy'], [$authMiddleware]);

// -- Kalenderquellen: ICS/CSV-Import & ICS-Abos (geschützt) -------------
$calendarSources = new CalendarSourceController();
$router->get('/api/calendar-sources', [$calendarSources, 'index'], [$authMiddleware]);
$router->post('/api/calendar-sources/import-ics', [$calendarSources, 'importIcs'], [$authMiddleware]);
$router->post('/api/calendar-sources/import-csv', [$calendarSources, 'importCsv'], [$authMiddleware]);
$router->post('/api/calendar-sources/stream', [$calendarSources, 'createStream'], [$authMiddleware]);
$router->post('/api/calendar-sources/{id}/sync', [$calendarSources, 'sync'], [$authMiddleware]);
$router->delete('/api/calendar-sources/{id}', [$calendarSources, 'destroy'], [$authMiddleware]);

// -- Healthcheck ------------------------------------------------------------
$router->get('/api/health', static function (): void {
    Response::json(['status' => 'ok', 'time' => date('c')]);
});

$router->dispatch($request);
