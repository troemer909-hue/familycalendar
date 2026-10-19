<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$res = $client->request('POST', '/api/task-lists', [
    'name' => trim($_POST['name'] ?? ''),
]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: task_lists.php?error=' . urlencode($res['data']['error'] ?? 'Liste konnte nicht angelegt werden.'));
    exit;
}

header('Location: task_list_view.php?id=' . (int) $res['data']['id']);
exit;
