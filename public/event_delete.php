<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);
$back = sanitizeCalendarBack($_POST['back'] ?? '');

if ($id) {
    $res = $client->request('DELETE', "/api/events/{$id}");
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: event_form.php?id=' . $id . '&back=' . urlencode($back)
            . '&error=' . urlencode($res['data']['error'] ?? 'Löschen fehlgeschlagen.'));
        exit;
    }
}

header('Location: calendar.php?' . $back);
exit;
