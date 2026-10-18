<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);

if ($id) {
    $res = $client->request('PUT', "/api/categories/{$id}", [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'color' => (string) ($_POST['color'] ?? ''),
    ]);
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: categories.php?error=' . urlencode($res['data']['error'] ?? 'Speichern fehlgeschlagen.'));
        exit;
    }
}

header('Location: categories.php?notice=' . urlencode('Kategorie gespeichert.'));
exit;
