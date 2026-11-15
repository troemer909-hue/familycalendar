<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$res = $client->request('POST', '/api/shopping-list/clear-checked');
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: shopping_list.php?error=' . urlencode($res['data']['error'] ?? 'Liste konnte nicht geleert werden.'));
    exit;
}

header('Location: shopping_list.php?notice=' . urlencode('Abgehakte Artikel entfernt.'));
exit;
