<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$id = (int) ($_POST['id'] ?? 0);

$res = $client->request('DELETE', "/api/household-stock/{$id}");
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: household_stock.php?error=' . urlencode($res['data']['error'] ?? 'Artikel konnte nicht entfernt werden.'));
    exit;
}

header('Location: household_stock.php?notice=' . urlencode('Artikel aus der Vorratsliste entfernt.'));
exit;
