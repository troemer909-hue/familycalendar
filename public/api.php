<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/apiRoutes.php';

use Familienkalender\Core\Env;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;

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
apiRouter()->dispatch($request);
