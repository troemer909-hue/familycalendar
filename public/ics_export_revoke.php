<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$res = $client->request('DELETE', '/api/ics-export/token');
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: ics_export.php?error=' . urlencode($res['data']['error'] ?? 'Deaktivieren fehlgeschlagen.'));
    exit;
}

header('Location: ics_export.php?notice=' . urlencode('Abo deaktiviert.'));
exit;
