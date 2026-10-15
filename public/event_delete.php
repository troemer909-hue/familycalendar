<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);
$backMonth = $_POST['back_month'] ?? date('Y-m');

if ($id) {
    $res = $client->request('DELETE', "/api/events/{$id}");
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: event_form.php?id=' . $id . '&back_month=' . urlencode($backMonth)
            . '&error=' . urlencode($res['data']['error'] ?? 'Löschen fehlgeschlagen.'));
        exit;
    }
}

header('Location: calendar.php?month=' . urlencode($backMonth));
exit;
