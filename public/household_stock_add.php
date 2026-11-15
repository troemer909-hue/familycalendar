<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$res = $client->request('POST', '/api/household-stock', [
    'label' => trim($_POST['label'] ?? ''),
]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: household_stock.php?error=' . urlencode($res['data']['error'] ?? 'Artikel konnte nicht hinzugefügt werden.'));
    exit;
}

header('Location: household_stock.php');
exit;
