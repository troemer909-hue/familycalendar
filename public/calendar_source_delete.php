<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);

if ($id) {
    $res = $client->request('DELETE', "/api/calendar-sources/{$id}");
    handleUnauthorized($res);

    if (!$res['ok']) {
        header('Location: calendar_sources.php?error=' . urlencode($res['data']['error'] ?? 'Entfernen fehlgeschlagen.'));
        exit;
    }
}

header('Location: calendar_sources.php?notice=' . urlencode('Quelle entfernt. Bereits importierte Termine bleiben erhalten.'));
exit;
