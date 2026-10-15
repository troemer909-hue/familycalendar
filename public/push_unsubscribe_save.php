<?php
require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$config = loadConfig();
if (empty($config['token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger Request-Body.']);
    exit;
}

$client = currentApiClient();
$res = $client->request('DELETE', '/api/push/subscribe', $body);

http_response_code($res['status'] ?: 500);
echo json_encode($res['data'] ?? ['error' => 'Unbekannter Fehler.']);
