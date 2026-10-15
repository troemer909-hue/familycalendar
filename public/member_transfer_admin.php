<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);

if ($id) {
    $res = $client->request('POST', "/api/members/{$id}/transfer-admin");
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: member_transfer_admin_confirm.php?id=' . $id . '&error=' . urlencode($res['data']['error'] ?? 'Admin-Übertragung fehlgeschlagen.'));
        exit;
    }
}

header('Location: members.php?notice=' . urlencode('Admin-Rolle übertragen.'));
exit;
