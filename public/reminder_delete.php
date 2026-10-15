<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);
$eventId = (int) ($_POST['event_id'] ?? 0);
$backMonth = $_POST['back_month'] ?? date('Y-m');

if ($id) {
    $res = $client->request('DELETE', "/api/reminders/{$id}");
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: event_form.php?id=' . $eventId . '&back_month=' . urlencode($backMonth)
            . '&error=' . urlencode($res['data']['error'] ?? 'Entfernen fehlgeschlagen.'));
        exit;
    }
}

header('Location: event_form.php?id=' . $eventId . '&back_month=' . urlencode($backMonth));
exit;
