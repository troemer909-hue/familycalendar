<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$res = $client->request('POST', '/api/self-update/run');
handleUnauthorized($res);
handleForbidden($res);

if (!$res['ok']) {
    // Die Fehlermeldung (z.B. roher Git-Stderr-Text bei einem fehlgeschlagenen
    // Merge) landet bewusst NICHT in der Redirect-URL: Git-Fehlermeldungen
    // enthalten oft Dateipfade (z.B. ".htaccess", "wp-config.php"-artige
    // Namen durch Drittanbieter-Dateien), die auf manchen Hosts von
    // generischen WAF-Regeln (z.B. ModSecurity-"sensible Datei"-Filter) als
    // Scan-Versuch missverstanden werden - selbst wenn nur der TEXT der
    // Fehlermeldung diese Zeichenkette enthält, nicht die URL selbst. Statt
    // in der URL wird die Meldung daher in der Session zwischengespeichert.
    $_SESSION['self_update_error'] = $res['data']['error'] ?? 'Update fehlgeschlagen.';
    header('Location: self_update.php');
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
