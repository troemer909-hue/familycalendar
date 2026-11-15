<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$id = (int) ($_POST['id'] ?? 0);

$res = $client->request('POST', "/api/household-stock/{$id}/flag-low");
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: household_stock.php?error=' . urlencode($res['data']['error'] ?? 'Artikel konnte nicht gemeldet werden.'));
    exit;
}

header('Location: household_stock.php?notice=' . urlencode('Artikel als "wird knapp" gemeldet und auf die Einkaufsliste gesetzt.'));
exit;
