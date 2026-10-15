<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$res = $client->request('POST', '/api/ics-export/token');
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: ics_export.php?error=' . urlencode($res['data']['error'] ?? 'Link konnte nicht erzeugt werden.'));
    exit;
}

header('Location: ics_export.php?notice=' . urlencode('Neuer Abo-Link erzeugt.'));
exit;
