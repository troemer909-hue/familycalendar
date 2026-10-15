<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

$redirectBack = function (string $message) use ($id): void {
    $target = $id ? "admin_plan_form.php?id={$id}&error=" : 'admin_plan_form.php?error=';
    header('Location: ' . $target . urlencode($message));
    exit;
};

$payload = [
    'code' => trim($_POST['code'] ?? ''),
    'name' => trim($_POST['name'] ?? ''),
    'max_members' => (int) ($_POST['max_members'] ?? 0),
    'max_calendar_streams' => (int) ($_POST['max_calendar_streams'] ?? 0),
    'price_monthly' => (float) ($_POST['price_monthly'] ?? 0),
];

$res = $id
    ? $client->request('PUT', "/api/admin/plans/{$id}", $payload)
    : $client->request('POST', '/api/admin/plans', $payload);

handleUnauthorized($res);
handleForbidden($res);

if (!$res['ok']) {
    $redirectBack($res['data']['error'] ?? 'Speichern fehlgeschlagen.');
}

header('Location: admin_plans.php?notice=' . urlencode('Plan gespeichert.'));
exit;
