<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$id = (int) ($_POST['id'] ?? 0);

$res = $client->request('DELETE', "/api/task-lists/{$id}");
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: task_lists.php?error=' . urlencode($res['data']['error'] ?? 'Liste konnte nicht gelöscht werden.'));
    exit;
}

header('Location: task_lists.php?notice=' . urlencode('Liste gelöscht.'));
exit;
