<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);

if ($id) {
    $res = $client->request('DELETE', "/api/categories/{$id}");
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: categories.php?error=' . urlencode($res['data']['error'] ?? 'Löschen fehlgeschlagen.'));
        exit;
    }
}

header('Location: categories.php?notice=' . urlencode('Kategorie gelöscht. Bereits vergebene Termine behalten ihre Beschriftung.'));
exit;
