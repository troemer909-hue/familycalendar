<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);

if ($id) {
    $res = $client->request('POST', "/api/calendar-sources/{$id}/sync");
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: calendar_sources.php?error=' . urlencode($res['data']['error'] ?? 'Synchronisierung fehlgeschlagen.'));
        exit;
    }
}

header('Location: calendar_sources.php?notice=' . urlencode('Synchronisierung abgeschlossen.'));
exit;
