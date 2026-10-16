<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$res = $client->request('POST', '/api/self-update/run');
handleUnauthorized($res);
handleForbidden($res);

if (!$res['ok']) {
    header('Location: self_update.php?error=' . urlencode($res['data']['error'] ?? 'Update fehlgeschlagen.'));
    exit;
}

$data = $res['data'];
if ($data['already_up_to_date']) {
    $notice = 'Bereits auf dem aktuellen Stand.';
} else {
    $notice = sprintf(
        'Update erfolgreich: %s -> %s. Angewendete Migrationen: %s.',
        substr((string) $data['old_commit'], 0, 12),
        substr((string) $data['new_commit'], 0, 12),
        $data['migrations_applied'] === [] ? 'keine' : implode(', ', $data['migrations_applied'])
    );
}

header('Location: self_update.php?notice=' . urlencode($notice));
exit;
