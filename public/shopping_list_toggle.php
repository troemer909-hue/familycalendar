<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$id = (int) ($_POST['id'] ?? 0);
$checked = ($_POST['checked'] ?? '0') === '1';

$res = $client->request('PUT', "/api/shopping-list/{$id}", ['checked' => $checked]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: shopping_list.php?error=' . urlencode($res['data']['error'] ?? 'Artikel konnte nicht aktualisiert werden.'));
    exit;
}

header('Location: shopping_list.php');
exit;
