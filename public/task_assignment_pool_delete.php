<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$listId = (int) ($_POST['list_id'] ?? 0);

$res = $client->request('DELETE', "/api/task-lists/{$listId}/assignment-pool");
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: task_lists.php?open=' . $listId . '&error=' . urlencode($res['data']['error'] ?? 'Zufalls-Zuteilung konnte nicht entfernt werden.') . '#list-' . $listId);
    exit;
}

header('Location: task_lists.php?open=' . $listId . '&notice=' . urlencode('Zufalls-Zuteilung entfernt.') . '#list-' . $listId);
exit;
