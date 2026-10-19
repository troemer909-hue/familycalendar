<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$listId = (int) ($_POST['list_id'] ?? 0);
$assignedMemberId = !empty($_POST['assigned_member_id']) ? (int) $_POST['assigned_member_id'] : null;

$res = $client->request('POST', "/api/task-lists/{$listId}/tasks", [
    'title' => trim($_POST['title'] ?? ''),
    'assigned_member_id' => $assignedMemberId,
    'due_date' => trim($_POST['due_date'] ?? ''),
]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: task_list_view.php?id=' . $listId . '&error=' . urlencode($res['data']['error'] ?? 'Aufgabe konnte nicht hinzugefügt werden.'));
    exit;
}

header('Location: task_list_view.php?id=' . $listId);
exit;
