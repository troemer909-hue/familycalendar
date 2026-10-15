<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);
$email = trim($_POST['email'] ?? '');

if (!$id || $email === '') {
    header('Location: member_invite_form.php?id=' . $id . '&error=' . urlencode('E-Mail ist erforderlich.'));
    exit;
}

$res = $client->request('POST', "/api/members/{$id}/invite", ['email' => $email]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: member_invite_form.php?id=' . $id . '&error=' . urlencode($res['data']['error'] ?? 'Einladung konnte nicht versendet werden.'));
    exit;
}

header('Location: members.php?notice=' . urlencode('Einladung versendet.'));
exit;
