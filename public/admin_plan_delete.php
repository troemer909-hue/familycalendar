<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);

if ($id) {
    $res = $client->request('DELETE', "/api/admin/plans/{$id}");
    handleUnauthorized($res);
    handleForbidden($res);

    if (!$res['ok']) {
        header('Location: admin_plans.php?error=' . urlencode($res['data']['error'] ?? 'Löschen fehlgeschlagen.'));
        exit;
    }
}

header('Location: admin_plans.php?notice=' . urlencode('Plan gelöscht.'));
exit;
