<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);
$planId = trim($_POST['plan_id'] ?? '');
$status = $_POST['status'] ?? '';

if (!$id) {
    header('Location: admin_families.php?error=' . urlencode('Ungültige Familie.'));
    exit;
}

$res = $client->request('PUT', "/api/admin/families/{$id}", [
    'plan_id' => $planId === '' ? null : (int) $planId,
    'status' => $status,
]);
handleUnauthorized($res);
handleForbidden($res);

if (!$res['ok']) {
    header('Location: admin_families.php?error=' . urlencode($res['data']['error'] ?? 'Aktualisierung fehlgeschlagen.'));
    exit;
}

header('Location: admin_families.php?notice=' . urlencode('Familie aktualisiert.'));
exit;
