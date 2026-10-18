<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$res = $client->request('POST', '/api/categories', [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'color' => (string) ($_POST['color'] ?? ''),
]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: categories.php?error=' . urlencode($res['data']['error'] ?? 'Kategorie konnte nicht angelegt werden.'));
    exit;
}

header('Location: categories.php?notice=' . urlencode('Kategorie angelegt.'));
exit;
