<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$id = (int) ($_POST['id'] ?? 0);
$listId = (int) ($_POST['list_id'] ?? 0);
$autoAssign = ($_POST['auto_assign'] ?? '1') === '1';

$res = $client->request('PUT', "/api/tasks/{$id}", ['auto_assign' => $autoAssign]);
handleUnauthorized($res);

$fromForm = $_POST['redirect'] ?? null;

if (!$res['ok']) {
    header('Location: ' . taskListRedirectUrl($listId, $fromForm, $res['data']['error'] ?? 'Aufgabe konnte nicht aktualisiert werden.'));
    exit;
}

header('Location: ' . taskListRedirectUrl($listId, $fromForm));
exit;
