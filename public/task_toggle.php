<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$id = (int) ($_POST['id'] ?? 0);
$listId = (int) ($_POST['list_id'] ?? 0);
$isDone = ($_POST['is_done'] ?? '0') === '1';

$res = $client->request('PUT', "/api/tasks/{$id}", ['is_done' => $isDone]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: task_list_view.php?id=' . $listId . '&error=' . urlencode($res['data']['error'] ?? 'Aufgabe konnte nicht aktualisiert werden.'));
    exit;
}

header('Location: task_list_view.php?id=' . $listId);
exit;
