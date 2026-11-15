<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$id = (int) ($_POST['id'] ?? 0);

$res = $client->request('DELETE', "/api/shopping-list/{$id}");
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: shopping_list.php?error=' . urlencode($res['data']['error'] ?? 'Artikel konnte nicht gelöscht werden.'));
    exit;
}

header('Location: shopping_list.php');
exit;
