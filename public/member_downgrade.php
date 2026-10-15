<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);

if ($id) {
    $res = $client->request('DELETE', "/api/members/{$id}/login");
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: members.php?error=' . urlencode($res['data']['error'] ?? 'Downgrade fehlgeschlagen.'));
        exit;
    }
}

header('Location: members.php');
exit;
