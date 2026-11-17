<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$listId = (int) ($_POST['list_id'] ?? 0);

$memberIds = array_map('intval', $_POST['member_ids'] ?? []);
$maxTasksByMember = $_POST['max_tasks'] ?? [];

$members = [];
foreach ($memberIds as $memberId) {
    $raw = $maxTasksByMember[$memberId] ?? '';
    $members[] = [
        'family_member_id' => $memberId,
        'max_tasks' => $raw !== '' ? (int) $raw : null,
    ];
}

$res = $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
    'members' => $members,
    'rhythm' => $_POST['rhythm'] ?? '',
    'mode' => $_POST['mode'] ?? 'fair',
    'active' => isset($_POST['active']),
]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: task_lists.php?open=' . $listId . '&error=' . urlencode($res['data']['error'] ?? 'Zufalls-Zuteilung konnte nicht gespeichert werden.') . '#list-' . $listId);
    exit;
}

header('Location: task_lists.php?open=' . $listId . '&notice=' . urlencode('Zufalls-Zuteilung gespeichert.') . '#list-' . $listId);
exit;
