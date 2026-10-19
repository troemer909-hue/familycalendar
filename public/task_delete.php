<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$id = (int) ($_POST['id'] ?? 0);
$listId = (int) ($_POST['list_id'] ?? 0);

$res = $client->request('DELETE', "/api/tasks/{$id}");
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: task_list_view.php?id=' . $listId . '&error=' . urlencode($res['data']['error'] ?? 'Aufgabe konnte nicht gelöscht werden.'));
    exit;
}

header('Location: task_list_view.php?id=' . $listId);
exit;
